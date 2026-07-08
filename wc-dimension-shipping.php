<?php
/**
 * Plugin Name: WC Dimension Shipping
 * Description: Zaawansowana wysyłka wymiarowa dla WooCommerce. Automatycznie oblicza koszt wysyłki na podstawie fizycznych wymiarów produktów (algorytm 3D bin-packing). Obsługuje dowolną liczbę rozmiarów paczek i przesyłkę paletową - każda jako osobna metoda z wariantem przedpłata i pobranie. Produkty ponadgabarytowe (niezmieszczące się w żadnej paczce) automatycznie wykluczają metody paczkowe - klient widzi wtedy tylko paletę. Konfiguracja bez edycji kodu z panelu admina: wymiary, wagi, ceny, stawka VAT. Dodatkowe funkcje: wyświetlanie ceny netto i brutto, automatyczny wybór najtańszej metody wysyłki, synchronizacja metod płatności z formą dostawy (pobranie wymusza COD, przedpłata ukrywa COD).
 * Version: 3.3.0
 * Author: Jakub Skorupa
 * Author URI: https://skorupa.net.pl/
 * Text Domain: wc-dimension-shipping
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ═══════════════════════════════════════════════════════════════════════════════
// STAŁE
// ═══════════════════════════════════════════════════════════════════════════════
define( 'WCDS_OPTION_BOXES',    'wc_dimension_shipping_boxes' );
define( 'WCDS_OPTION_PALLET',   'wc_dimension_shipping_pallet' );
define( 'WCDS_OPTION_SETTINGS', 'wc_dimension_shipping_settings' );
define( 'WCDS_PAGE_SLUG',       'wc-dimension-shipping' );

// ═══════════════════════════════════════════════════════════════════════════════
// DOMYŚLNE WARTOŚCI
// ═══════════════════════════════════════════════════════════════════════════════
function wcds_default_boxes(): array {
    return [
        [
            'name'       => 'Mała paczka',
            'length'     => 25,
            'width'      => 40,
            'height'     => 20,
            'max_weight' => 5,
            'price'      => 25,
            'cod_price'  => 30,
        ],
        [
            'name'       => 'Duża paczka',
            'length'     => 50,
            'width'      => 40,
            'height'     => 30,
            'max_weight' => 10,
            'price'      => 30,
            'cod_price'  => 38,
        ],
    ];
}

function wcds_default_pallet(): array {
    return [
        'enabled'    => true,
        'name'       => 'Przesyłka paletowa',
        'length'     => 120,
        'width'      => 80,
        'height'     => 180,
        'max_weight' => 200,
        'price'      => 260,
        'cod_price'  => 310,
    ];
}

// ═══════════════════════════════════════════════════════════════════════════════
// AKTYWACJA
// ═══════════════════════════════════════════════════════════════════════════════
register_activation_hook( __FILE__, function () {
    if ( ! get_option( WCDS_OPTION_BOXES ) )  update_option( WCDS_OPTION_BOXES,  wcds_default_boxes()  );
    if ( ! get_option( WCDS_OPTION_PALLET ) ) update_option( WCDS_OPTION_PALLET, wcds_default_pallet() );
});

// ═══════════════════════════════════════════════════════════════════════════════
// GETTERY
// ═══════════════════════════════════════════════════════════════════════════════
function wcds_get_boxes(): array {
    $b = get_option( WCDS_OPTION_BOXES, [] );
    return ( is_array($b) && ! empty($b) ) ? $b : wcds_default_boxes();
}

function wcds_get_pallet(): array {
    $p = get_option( WCDS_OPTION_PALLET, [] );
    return ( is_array($p) && ! empty($p) ) ? $p : wcds_default_pallet();
}

function wcds_get_settings(): array {
    $s = get_option( WCDS_OPTION_SETTINGS, [] );
    return wp_parse_args( is_array($s) ? $s : [], [
        'prices_are_netto' => false,
        'vat_rate'         => 23,
    ]);
}

// ═══════════════════════════════════════════════════════════════════════════════
// PANEL ADMINA
// ═══════════════════════════════════════════════════════════════════════════════
add_action( 'admin_menu', function () {
    add_submenu_page(
        'woocommerce',
        'Konfiguracja wysyłki',
        'Paczki wymiarowe',
        'manage_woocommerce',
        WCDS_PAGE_SLUG,
        'wcds_admin_page'
    );
});

// ─── Zapis ────────────────────────────────────────────────────────────────────
add_action( 'admin_init', function () {
    if ( ! isset($_POST['wcds_save']) || ! check_admin_referer('wcds_save','wcds_nonce') || ! current_user_can('manage_woocommerce') ) return;

    // --- Paczki ---
    $raw   = $_POST['wcds_boxes'] ?? [];
    $boxes = [];
    foreach ( $raw as $box ) {
        $name = sanitize_text_field( $box['name'] ?? '' );
        if ( $name === '' ) continue;
        $boxes[] = [
            'name'       => $name,
            'length'     => max(1,   (float)($box['length']     ?? 1)),
            'width'      => max(1,   (float)($box['width']      ?? 1)),
            'height'     => max(1,   (float)($box['height']     ?? 1)),
            'max_weight' => max(0.1, (float)($box['max_weight'] ?? 1)),
            'price'      => max(0,   (float)($box['price']      ?? 0)),
            'cod_price'  => max(0,   (float)($box['cod_price']  ?? 0)),
        ];
    }

    // --- Paleta ---
    $rp = $_POST['wcds_pallet'] ?? [];
    $pallet = [
        'enabled'    => ! empty($rp['enabled']),
        'name'       => sanitize_text_field($rp['name']       ?? 'Przesyłka paletowa'),
        'length'     => max(1,   (float)($rp['length']     ?? 120)),
        'width'      => max(1,   (float)($rp['width']      ?? 80)),
        'height'     => max(1,   (float)($rp['height']     ?? 180)),
        'max_weight' => max(1,   (float)($rp['max_weight'] ?? 200)),
        'price'      => max(0,   (float)($rp['price']      ?? 260)),
        'cod_price'  => max(0,   (float)($rp['cod_price']  ?? 310)),
    ];

    // --- Ustawienia ogólne ---
    $rs = $_POST['wcds_settings'] ?? [];
    $settings = [
        'prices_are_netto' => ! empty($rs['prices_are_netto']),
        'vat_rate'         => min(100, max(0, (float)($rs['vat_rate'] ?? 23))),
    ];

    if ( ! empty($boxes) ) {
        update_option( WCDS_OPTION_BOXES,    $boxes    );
        update_option( WCDS_OPTION_PALLET,   $pallet   );
        update_option( WCDS_OPTION_SETTINGS, $settings );
        WC_Cache_Helper::get_transient_version( 'shipping', true );
        add_action( 'admin_notices', fn() =>
            print '<div class="notice notice-success is-dismissible"><p><strong>Ustawienia zapisane.</strong> Cache wysyłki wyczyszczony.</p></div>'
        );
    } else {
        add_action( 'admin_notices', fn() =>
            print '<div class="notice notice-error is-dismissible"><p>Błąd: musisz mieć co najmniej jedną paczkę.</p></div>'
        );
    }
});

// ─── Style ───────────────────────────────────────────────────────────────────
add_action( 'admin_head', function () {
    $screen = get_current_screen();
    if ( ! $screen || strpos($screen->id, WCDS_PAGE_SLUG) === false ) return;
    ?>
    <style>
    #wcds-wrap { max-width: 900px; }
    #wcds-wrap h1 { margin-bottom: 6px; }
    .wcds-desc { color:#666; margin-bottom:24px; font-size:14px; }
    .wcds-section-title { font-size:16px; font-weight:600; color:#1d2327; margin:32px 0 12px; border-bottom:2px solid #f0f0f1; padding-bottom:8px; }
    .wcds-boxes { display:flex; flex-direction:column; gap:14px; margin-bottom:10px; }
    .wcds-box-card, .wcds-pallet-card {
        background:#fff; border:1px solid #dcdcde; border-radius:6px; padding:18px 22px; position:relative;
    }
    .wcds-pallet-card { border-left:4px solid #f0a500; }
    .wcds-pallet-card.disabled { opacity:.55; }
    .wcds-box-card h3, .wcds-pallet-card h3 {
        margin:0 0 14px; font-size:14px; font-weight:600; display:flex; align-items:center; gap:8px; color:#1d2327;
    }
    .wcds-badge { background:#f0f0f1; border-radius:4px; font-size:11px; font-weight:400; padding:2px 8px; color:#646970; }
    .wcds-badge-pallet { background:#fff4e0; border-radius:4px; font-size:11px; padding:2px 8px; color:#8a5c00; }
    .wcds-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(120px,1fr)); gap:12px; }
    .wcds-full { grid-column:1/-1; }
    .wcds-grid label { display:block; font-size:12px; font-weight:600; color:#50575e; margin-bottom:4px; }
    .wcds-grid input[type="text"], .wcds-grid input[type="number"] { width:100%; box-sizing:border-box; }
    .wcds-divider { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:#b4b9be; margin:14px 0 10px; padding-bottom:6px; border-bottom:1px solid #f0f0f1; }
    .wcds-remove { position:absolute; top:14px; right:16px; background:none; border:1px solid #dcdcde; border-radius:4px; cursor:pointer; color:#b32d2e; font-size:12px; padding:4px 10px; line-height:1.4; }
    .wcds-remove:hover { background:#fcf0f1; border-color:#b32d2e; }
    #wcds-add-box { width:100%; background:#f6f7f7; border:1px dashed #c3c4c7; border-radius:6px; padding:12px; cursor:pointer; font-size:13px; color:#646970; text-align:center; margin-bottom:24px; }
    #wcds-add-box:hover { background:#f0f6ff; border-color:#2271b1; color:#2271b1; }
    .wcds-footer { display:flex; align-items:center; gap:16px; margin-top:24px; }
    .wcds-hint { font-size:12px; color:#8c8f94; }
    .wcds-info-box { background:#f0f6fc; border-left:4px solid #2271b1; padding:12px 16px; border-radius:0 4px 4px 0; font-size:13px; color:#1d2327; margin-bottom:22px; line-height:1.6; }
    .wcds-pallet-toggle { display:flex; align-items:center; gap:10px; margin-bottom:14px; font-size:13px; color:#1d2327; }
    .wcds-pallet-toggle input[type="checkbox"] { width:18px; height:18px; cursor:pointer; }
    .wcds-pallet-fields { transition: opacity .2s; }
    </style>
    <?php
});

// ─── Strona admina ────────────────────────────────────────────────────────────
function wcds_admin_page() {
    $boxes  = wcds_get_boxes();
    $pallet = wcds_get_pallet();
    $pal_en = ! empty($pallet['enabled']);
    ?>
    <div class="wrap" id="wcds-wrap">
        <h1>Wysyłka wymiarowa - konfiguracja</h1>
        <p class="wcds-desc">Definiuj rozmiary paczek, przesyłkę paletową i ustawienia ogólne. Wszystkie zmiany działają natychmiast po zapisaniu.</p>

        <div class="wcds-info-box">
            <strong>Jak to działa:</strong>
            <ul style="margin:6px 0 0 16px;padding:0;line-height:1.8;">
                <li>Plugin mierzy fizyczne wymiary każdego produktu w koszyku i dobiera optymalny rozmiar paczki algorytmem 3D (nie samą objętość).</li>
                <li>Jeśli produkt nie mieści się w żadnej paczce - metody paczkowe są ukryte, klient widzi <strong>tylko paletę</strong>.</li>
                <li>Klient widzi osobne opcje: <strong>Kurier - Przedpłata</strong>, <strong>Kurier - Pobranie</strong>, <strong>Paleta - Przedpłata</strong>, <strong>Paleta - Pobranie</strong>. Domyślnie zaznaczona jest najtańsza dostępna opcja.</li>
                <li>Wybór pobrania automatycznie ukrywa metody płatności inne niż COD. Wybór przedpłaty ukrywa COD.</li>
                <li>Gdy włączone ceny netto - pod każdą stawką wyświetlana jest kwota netto i wyliczone brutto.</li>
            </ul>
        </div>

        <form method="post" id="wcds-form">
            <?php wp_nonce_field('wcds_save','wcds_nonce'); ?>

            <!-- ═══ PACZKI ═══ -->
            <div class="wcds-section-title">Rozmiary paczek</div>
            <div class="wcds-boxes" id="wcds-boxes">
                <?php foreach ( $boxes as $i => $box ) echo wcds_box_card_html($i, $box); ?>
            </div>
            <button type="button" id="wcds-add-box">＋ Dodaj nowy rozmiar paczki</button>

            <!-- ═══ PALETA ═══ -->
            <div class="wcds-section-title">Przesyłka paletowa</div>
            <div class="wcds-pallet-card <?php echo $pal_en ? '' : 'disabled'; ?>" id="wcds-pallet-card">
                <h3>
                    <span><?php echo esc_html($pallet['name']); ?></span>
                    <span class="wcds-badge-pallet">paleta</span>
                </h3>

                <div class="wcds-pallet-toggle">
                    <input type="checkbox" name="wcds_pallet[enabled]" id="wcds-pallet-enabled" value="1" <?php checked($pal_en); ?>>
                    <label for="wcds-pallet-enabled">Udostępnij opcję palety klientom w kasie</label>
                </div>

                <div class="wcds-pallet-fields" id="wcds-pallet-fields" style="opacity:<?php echo $pal_en ? 1 : .4; ?>; pointer-events:<?php echo $pal_en ? 'auto' : 'none'; ?>">
                    <div class="wcds-grid">
                        <div class="wcds-full">
                            <label>Nazwa (widoczna dla klienta)</label>
                            <input type="text" name="wcds_pallet[name]" value="<?php echo esc_attr($pallet['name']); ?>" required>
                        </div>
                    </div>

                    <div class="wcds-divider">Wymiary maksymalne palety (cm)</div>
                    <div class="wcds-grid">
                        <div><label>Długość</label><input type="number" name="wcds_pallet[length]" value="<?php echo esc_attr($pallet['length']); ?>" min="1" step="1"></div>
                        <div><label>Szerokość</label><input type="number" name="wcds_pallet[width]" value="<?php echo esc_attr($pallet['width']); ?>" min="1" step="1"></div>
                        <div><label>Wysokość</label><input type="number" name="wcds_pallet[height]" value="<?php echo esc_attr($pallet['height']); ?>" min="1" step="1"></div>
                        <div><label>Maks. waga (kg)</label><input type="number" name="wcds_pallet[max_weight]" value="<?php echo esc_attr($pallet['max_weight']); ?>" min="1" step="1"></div>
                    </div>

                    <div class="wcds-divider">Ceny (zł)</div>
                    <div class="wcds-grid">
                        <div><label>Przelew / karta</label><input type="number" name="wcds_pallet[price]" value="<?php echo esc_attr($pallet['price']); ?>" min="0" step="0.01"></div>
                        <div><label>Pobranie (COD)</label><input type="number" name="wcds_pallet[cod_price]" value="<?php echo esc_attr($pallet['cod_price'] ?? $pallet['price']); ?>" min="0" step="0.01"></div>
                        <div style="display:flex;align-items:flex-end;padding-bottom:2px;grid-column:1/-1;">
                            <p style="margin:0;font-size:12px;color:#646970;line-height:1.5;">Stała stawka - jedna paleta mieści wszystko do <?php echo esc_html($pallet['max_weight']); ?> kg.</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- USTAWIENIA OGÓLNE -->
            <?php $settings = wcds_get_settings(); ?>
            <div class="wcds-section-title">Ustawienia ogólne</div>
            <div class="wcds-box-card">
                <div class="wcds-pallet-toggle" style="margin-bottom:12px;">
                    <input type="checkbox" name="wcds_settings[prices_are_netto]" id="wcds-prices-netto" value="1" <?php checked( ! empty($settings['prices_are_netto']) ); ?>>
                    <label for="wcds-prices-netto">Ceny w pluginie są cenami <strong>netto</strong> - pokazuj klientowi kwotę netto i brutto</label>
                </div>
                <div id="wcds-vat-row" style="display:<?php echo ! empty($settings['prices_are_netto']) ? 'flex' : 'none'; ?>;align-items:center;gap:10px;margin-top:4px;">
                    <label style="font-size:12px;font-weight:600;color:#50575e;white-space:nowrap;">Stawka VAT (%)</label>
                    <input type="number" name="wcds_settings[vat_rate]" value="<?php echo esc_attr($settings['vat_rate']); ?>" min="0" max="100" step="1" style="width:80px;">
                    <span style="font-size:12px;color:#646970;">Np. 23 dla stawki 23%. Brutto = netto × (1 + VAT/100).</span>
                </div>
            </div>

            <div class="wcds-footer">
                <input type="submit" name="wcds_save" class="button button-primary button-large" value="Zapisz ustawienia">
                <span class="wcds-hint">Zmiany działają natychmiast po zapisaniu.</span>
            </div>
        </form>
    </div>

    <script>
    (function(){
        // Toggle wiersza VAT
        var nettoChk = document.getElementById('wcds-prices-netto');
        var vatRow   = document.getElementById('wcds-vat-row');
        if (nettoChk) {
            nettoChk.addEventListener('change', function(){
                vatRow.style.display = this.checked ? 'flex' : 'none';
            });
        }

        // Toggle palety
        var palChk = document.getElementById('wcds-pallet-enabled');
        var palFields = document.getElementById('wcds-pallet-fields');
        var palCard = document.getElementById('wcds-pallet-card');
        palChk.addEventListener('change', function(){
            palFields.style.opacity = this.checked ? '1' : '0.4';
            palFields.style.pointerEvents = this.checked ? 'auto' : 'none';
            palCard.classList.toggle('disabled', !this.checked);
        });

        // Dodaj paczkę
        var idx = <?php echo count($boxes); ?>;
        var tpl = <?php echo json_encode( wcds_box_card_html('__IDX__', ['name'=>'Nowa paczka','length'=>30,'width'=>30,'height'=>20,'max_weight'=>5,'price'=>20,'cod_price'=>25]) ); ?>;
        document.getElementById('wcds-add-box').addEventListener('click', function(){
            var wrap = document.getElementById('wcds-boxes');
            var div = document.createElement('div');
            div.innerHTML = tpl.replace(/__IDX__/g, String(idx));
            wrap.appendChild(div.firstElementChild);
            updateBadges(); idx++;
        });

        // Usuń paczkę
        document.getElementById('wcds-boxes').addEventListener('click', function(e){
            if ( e.target.classList.contains('wcds-remove') ) {
                if ( document.querySelectorAll('.wcds-box-card').length <= 1 ) { alert('Musisz mieć co najmniej jedną paczkę.'); return; }
                e.target.closest('.wcds-box-card').remove();
                updateBadges();
            }
        });

        // Podgląd nazwy na żywo
        document.getElementById('wcds-boxes').addEventListener('input', function(e){
            if ( e.target.dataset.nameInput ) {
                var p = e.target.closest('.wcds-box-card').querySelector('.wcds-name-preview');
                if(p) p.textContent = e.target.value || 'Nowa paczka';
            }
        });

        function updateBadges(){
            document.querySelectorAll('.wcds-box-card').forEach(function(c,i){
                var b = c.querySelector('.wcds-idx'); if(b) b.textContent = i+1;
            });
        }
    })();
    </script>
    <?php
}

function wcds_box_card_html( $i, array $box ): string {
    $name = htmlspecialchars($box['name'] ?? 'Paczka', ENT_QUOTES);
    $l    = esc_attr($box['length']     ?? 30);
    $w    = esc_attr($box['width']      ?? 30);
    $h    = esc_attr($box['height']     ?? 20);
    $mw   = esc_attr($box['max_weight'] ?? 5);
    $p    = esc_attr($box['price']      ?? 0);
    $cp   = esc_attr($box['cod_price']  ?? 0);
    $ii   = (int) filter_var($i, FILTER_SANITIZE_NUMBER_INT);
    return '
<div class="wcds-box-card">
    <button type="button" class="wcds-remove">Usuń</button>
    <h3><span class="wcds-name-preview">'.$name.'</span><span class="wcds-badge">paczka #<span class="wcds-idx">'.($ii+1).'</span></span></h3>
    <div class="wcds-grid">
        <div class="wcds-full"><label>Nazwa paczki</label>
        <input type="text" name="wcds_boxes['.$i.'][name]" value="'.$name.'" data-name-input="1" placeholder="np. Mała paczka" required></div>
    </div>
    <div class="wcds-divider">Wymiary maksymalne (cm)</div>
    <div class="wcds-grid">
        <div><label>Długość</label><input type="number" name="wcds_boxes['.$i.'][length]" value="'.$l.'" min="1" step="0.5" required></div>
        <div><label>Szerokość</label><input type="number" name="wcds_boxes['.$i.'][width]" value="'.$w.'" min="1" step="0.5" required></div>
        <div><label>Wysokość</label><input type="number" name="wcds_boxes['.$i.'][height]" value="'.$h.'" min="1" step="0.5" required></div>
        <div><label>Maks. waga (kg)</label><input type="number" name="wcds_boxes['.$i.'][max_weight]" value="'.$mw.'" min="0.1" step="0.1" required></div>
    </div>
    <div class="wcds-divider">Ceny (zł)</div>
    <div class="wcds-grid">
        <div><label>Przelew / karta</label><input type="number" name="wcds_boxes['.$i.'][price]" value="'.$p.'" min="0" step="0.01" required></div>
        <div><label>Pobranie (COD)</label><input type="number" name="wcds_boxes['.$i.'][cod_price]" value="'.$cp.'" min="0" step="0.01" required></div>
    </div>
</div>';
}

// ═══════════════════════════════════════════════════════════════════════════════
// METODY WYSYŁKI - rejestracja
// ═══════════════════════════════════════════════════════════════════════════════
add_action( 'woocommerce_shipping_init', 'wcds_register_shipping_methods' );

function wcds_register_shipping_methods() {

    // ─────────────────────────────────────────────────────────────────────────
    // 1. PACZKI WYMIAROWE
    // ─────────────────────────────────────────────────────────────────────────
    class WCDS_Parcel_Method extends WC_Shipping_Method {

        public function __construct( $instance_id = 0 ) {
            $this->id                 = 'wcds_parcel';
            $this->instance_id        = absint($instance_id);
            $this->method_title       = 'Wysyłka paczkowa (wg wymiarów)';
            $this->method_description = 'Automatyczne obliczanie kosztu wg wymiarów produktów. Konfiguracja: WooCommerce → Paczki wymiarowe.';
            $this->supports           = ['shipping-zones','instance-settings'];
            $this->title              = 'Wysyłka paczkowa';
            $this->enabled            = 'yes';
            $this->init_form_fields();
            $this->init_settings();
            add_action( 'woocommerce_update_options_shipping_'.$this->id, [$this,'process_admin_options'] );
        }

        public function init_form_fields() {
            $this->instance_form_fields = [
                'title' => ['title'=>'Tytuł metody','type'=>'text','default'=>'Wysyłka paczkowa','description'=>'Etykieta widoczna w kasie.'],
            ];
        }

        private function fits( array $dims, array $box ): bool {
            rsort($dims);
            $b = [(float)$box['length'],(float)$box['width'],(float)$box['height']]; rsort($b);
            return $dims[0]<=$b[0] && $dims[1]<=$b[1] && $dims[2]<=$b[2];
        }

        /**
         * Oblicza ile sztuk danego produktu (o wymiarach $il×$iw×$ih) fizycznie
         * mieści się w pudełku $box, próbując wszystkie 6 orientacji produktu.
         */
        private function items_per_box( float $il, float $iw, float $ih, array $box ): int {
            $bl = (float)$box['length'];
            $bw = (float)$box['width'];
            $bh = (float)$box['height'];
            $orientations = [
                [$il,$iw,$ih], [$il,$ih,$iw],
                [$iw,$il,$ih], [$iw,$ih,$il],
                [$ih,$il,$iw], [$ih,$iw,$il],
            ];
            $best = 0;
            foreach ($orientations as [$x,$y,$z]) {
                if ($x>$bl || $y>$bw || $z>$bh) continue;
                $c = (int)floor($bl/$x) * (int)floor($bw/$y) * (int)floor($bh/$z);
                if ($c > $best) $best = $c;
            }
            return $best;
        }

        /**
         * Pakuje listę produktów do paczek danego rozmiaru.
         * Każda paczka przechowuje listę dodanych produktów i sprawdza
         * fizyczny limit przestrzenny metodą items_per_box() dla KAŻDEGO nowego produktu.
         *
         * Paczka jest "pełna" gdy:
         *  - kolejny produkt nie mieści się fizycznie (items_per_box=0 dla pozostałej przestrzeni), LUB
         *  - przekroczony byłby limit wagi.
         *
         * Uproszczenie: traktujemy każdą paczkę jako niezależną przestrzeń
         * i liczymy ile sztuk KAŻDEGO typu produktu mogłoby w niej być (3D grid),
         * a następnie odejmujemy już umieszczone. Działa świetnie dla jednorodnych
         * produktów i dobrze aproksymuje dla mieszanych.
         */
        /**
         * Pakuje listę produktów do paczek danego rozmiaru.
         * Śledzi fizyczny limit (items_per_box) PER wymiar produktu ORAZ wagę.
         * Zwraca liczbę potrzebnych paczek, lub NULL jeśli któryś produkt w ogóle nie pasuje.
         */
        private function pack_into_box( array $items, array $box ): ?int {
            $pkgs = []; // każda: ['by_dim'=>[(l,w,h)=>count], 'wt'=>float]
            foreach ($items as $item) {
                $key = $item['l'].'x'.$item['w'].'x'.$item['h'];
                $cap = $this->items_per_box($item['l'], $item['w'], $item['h'], $box);
                if ($cap <= 0) return null; // produkt nie mieści się w ogóle
                $placed = false;
                foreach ($pkgs as &$pkg) {
                    $already = $pkg['by_dim'][$key] ?? 0;
                    if ($already < $cap && $pkg['wt'] + $item['wt'] <= (float)$box['max_weight']) {
                        $pkg['by_dim'][$key] = $already + 1;
                        $pkg['wt'] += $item['wt'];
                        $placed = true;
                        break;
                    }
                }
                unset($pkg);
                if (!$placed) {
                    $pkgs[] = ['by_dim' => [$key => 1], 'wt' => $item['wt']];
                }
            }
            return count($pkgs);
        }

        /**
         * Optimizer kosztów: dla każdej możliwej liczby paczek "dużych" (0..max),
         * pakuje resztę w "mniejsze". Wybiera globalnie najtańszą kombinację.
         *
         * Algorytm:
         *  1. Sortuj pudełka od najmniejszego.
         *  2. Dla każdego rozmiaru jako "głównego" (od największego):
         *     Próbuj 0, 1, 2, ... paczek tego rozmiaru.
         *     Resztę produktów pakuj w mniejsze pudełka.
         *  3. Zwróć najtańszy wariant z opisem.
         */
        private function optimize_packing( array $items, array $boxes, string $price_key ): array {
            if (empty($items) || empty($boxes)) return ['cost' => 0, 'parts' => []];

            // Sortuj pudełka od najmniejszego do największego (wg objętości)
            usort($boxes, fn($a,$b) =>
                ($a['length']*$a['width']*$a['height']) <=> ($b['length']*$b['width']*$b['height'])
            );

            $best_cost  = PHP_INT_MAX;
            $best_state = []; // [['box'=>..., 'count'=>int], ...]

            $n_boxes = count($boxes);

            // Iteruj po każdym rozmiarze jako "głównym" (zazwyczaj largest)
            for ($bi_main = $n_boxes - 1; $bi_main >= 0; $bi_main--) {
                $box_main   = $boxes[$bi_main];
                $price_main = (float)$box_main[$price_key];

                // Ile paczek tego rozmiaru potrzeba gdyby pakować tylko w ten rozmiar?
                $n_max = $this->pack_into_box($items, $box_main);
                if ($n_max === null) continue; // żaden produkt nie pasuje

                // Próbuj każdą liczbę paczek głównych od 0 do n_max
                for ($n_main = 0; $n_main <= $n_max; $n_main++) {
                    $cost_main = $n_main * $price_main;
                    if ($cost_main >= $best_cost) break; // przycinanie gałęzi

                    if ($n_main === 0) {
                        $remaining = $items;
                    } else {
                        // Wciśnij tyle produktów ile się da do $n_main paczek box_main
                        // Otwarte paczki: każda śledzi by_dim i wagę
                        $open = array_fill(0, $n_main, ['by_dim' => [], 'wt' => 0.0]);
                        $remaining = [];
                        // Sortuj od największych (greedily najpierw duże)
                        $sorted = $items;
                        usort($sorted, fn($a,$b) => ($b['l']*$b['w']*$b['h']) <=> ($a['l']*$a['w']*$a['h']));
                        foreach ($sorted as $item) {
                            $key = $item['l'].'x'.$item['w'].'x'.$item['h'];
                            $cap = $this->items_per_box($item['l'], $item['w'], $item['h'], $box_main);
                            $placed = false;
                            if ($cap > 0) {
                                foreach ($open as &$pkg) {
                                    $already = $pkg['by_dim'][$key] ?? 0;
                                    if ($already < $cap && $pkg['wt'] + $item['wt'] <= (float)$box_main['max_weight']) {
                                        $pkg['by_dim'][$key] = $already + 1;
                                        $pkg['wt'] += $item['wt'];
                                        $placed = true;
                                        break;
                                    }
                                }
                                unset($pkg);
                            }
                            if (!$placed) $remaining[] = $item;
                        }
                    }

                    if (empty($remaining)) {
                        // Wszystko w paczkach głównych
                        $total = $cost_main;
                        if ($total < $best_cost) {
                            $best_cost  = $total;
                            $best_state = $n_main > 0 ? [['box' => $box_main, 'count' => $n_main]] : [];
                        }
                        break;
                    }

                    // Pakuj remaining w mniejsze pudełka (od bi=0 do bi_main)
                    $rem_cost  = 0.0;
                    $rem_state = [];
                    $leftover  = $remaining;

                    for ($bi_rest = 0; $bi_rest <= $bi_main && !empty($leftover); $bi_rest++) {
                        $box_rest = $boxes[$bi_rest];
                        // Wydziel produkty pasujące do tego pudełka
                        $fits   = [];
                        $no_fit = [];
                        foreach ($leftover as $item) {
                            if ($this->items_per_box($item['l'], $item['w'], $item['h'], $box_rest) > 0) {
                                $fits[] = $item;
                            } else {
                                $no_fit[] = $item;
                            }
                        }
                        if (!empty($fits)) {
                            $n = $this->pack_into_box($fits, $box_rest);
                            if ($n !== null) {
                                $c          = $n * (float)$box_rest[$price_key];
                                $rem_cost  += $c;
                                $rem_state[] = ['box' => $box_rest, 'count' => $n];
                                $leftover   = $no_fit;
                            }
                        }
                    }

                    if (!empty($leftover)) continue; // nie udało się spakować wszystkiego

                    $total = $cost_main + $rem_cost;
                    if ($total < $best_cost) {
                        $best_cost  = $total;
                        $best_state = array_values(array_filter(
                            array_merge(
                                $n_main > 0 ? [['box' => $box_main, 'count' => $n_main]] : [],
                                $rem_state
                            ),
                            fn($e) => $e['count'] > 0
                        ));
                    }
                }
            }

            // Zbuduj opis słowny
            $parts = [];
            foreach ($best_state as $entry) {
                $cost    = $entry['count'] * (float)$entry['box'][$price_key];
                $parts[] = sprintf('%d × %s (%s zł)', $entry['count'], $entry['box']['name'], number_format($cost, 2, ',', ' '));
            }

            return ['cost' => $best_cost === PHP_INT_MAX ? 0 : (float)$best_cost, 'parts' => $parts];
        }

        public function calculate_shipping( $package=[] ) {
            $boxes = wcds_get_boxes();
            if (empty($boxes)) return;

            usort($boxes, fn($a,$b)=>($a['length']*$a['width']*$a['height'])<=>($b['length']*$b['width']*$b['height']));
            $largest = end($boxes);

            // Rozwiń koszyk
            $all=[];
            foreach ($package['contents'] as $ci) {
                $pr=$ci['data']; $qty=$ci['quantity'];
                $l=max(1,(float)$pr->get_length()); $w=max(1,(float)$pr->get_width());
                $h=max(1,(float)$pr->get_height()); $kg=max(0.01,(float)$pr->get_weight());
                for($q=0;$q<$qty;$q++) $all[]=['l'=>$l,'w'=>$w,'h'=>$h,'wt'=>$kg];
            }
            if(empty($all)) return;

            $packable=[]; $oversized=[];
            foreach ($all as $item) {
                if ($this->fits([$item['l'],$item['w'],$item['h']],$largest)) $packable[]=$item;
                else $oversized[]=$item;
            }

            // Jeśli koszyk zawiera choć jeden produkt ponadgabarytowy -
            // metoda paczkowa w ogóle się nie pokazuje, klient musi wybrać paletę
            if (!empty($oversized)) return;

            // Oblicz koszt dla obu wariantów płatności
            foreach (['price'=>'Przedpłata', 'cod_price'=>'Pobranie'] as $price_key => $label_suffix) {
                $result = $this->optimize_packing($packable, $boxes, $price_key);
                $total  = $result['cost'];
                $parts  = $result['parts'];

                if ($total <= 0) continue;

                $base_title = $this->get_option('title', 'Wysyłka paczkowa');

                $this->add_rate([
                    'id'        => $this->id.'_'.$this->instance_id.'_'.$price_key,
                    'label'     => $base_title.' - '.$label_suffix,
                    'cost'      => $total,
                    'meta_data' => ['wcds_breakdown' => implode(', ', $parts)],
                ]);
            }
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 2. PALETA
    // ─────────────────────────────────────────────────────────────────────────
    class WCDS_Pallet_Method extends WC_Shipping_Method {

        public function __construct( $instance_id = 0 ) {
            $this->id                 = 'wcds_pallet';
            $this->instance_id        = absint($instance_id);
            $this->method_title       = 'Przesyłka paletowa';
            $this->method_description = 'Stała stawka za paletę. Konfiguracja: WooCommerce → Paczki wymiarowe.';
            $this->supports           = ['shipping-zones','instance-settings'];
            $this->title              = 'Przesyłka paletowa';
            $this->enabled            = 'yes';
            $this->init_form_fields();
            $this->init_settings();
            add_action( 'woocommerce_update_options_shipping_'.$this->id, [$this,'process_admin_options'] );
        }

        public function init_form_fields() {
            $this->instance_form_fields = [
                'title' => ['title'=>'Tytuł metody','type'=>'text','default'=>'Przesyłka paletowa','description'=>'Etykieta widoczna w kasie.'],
            ];
        }

        public function calculate_shipping( $package=[] ) {
            $pallet = wcds_get_pallet();

            if ( empty($pallet['enabled']) ) return;

            $total_weight = 0;
            foreach ($package['contents'] as $ci) {
                $total_weight += (float)$ci['data']->get_weight() * $ci['quantity'];
            }

            $dims         = sprintf('%d×%d×%d cm, do %d kg', $pallet['length'], $pallet['width'], $pallet['height'], $pallet['max_weight']);
            $over_warning = $total_weight > (float)$pallet['max_weight']
                ? sprintf(' (uwaga: zamówienie przekracza limit wagi %d kg)', (int)$pallet['max_weight'])
                : '';

            $base_title = $this->get_option('title', $pallet['name']);

            $variants = [
                'price'     => 'Przedpłata',
                'cod_price' => 'Pobranie',
            ];

            foreach ($variants as $price_key => $label_suffix) {
                $price = (float)($pallet[$price_key] ?? 0);
                if ($price <= 0) continue;

                $this->add_rate([
                    'id'        => $this->id.'_'.$this->instance_id.'_'.$price_key,
                    'label'     => $base_title.' - '.$label_suffix.$over_warning,
                    'cost'      => $price,
                    'meta_data' => ['wcds_pallet_dims' => $dims],
                ]);
            }
        }
    }
}

// ─── Rejestracja metod ────────────────────────────────────────────────────────
add_filter( 'woocommerce_shipping_methods', function($methods){
    $methods['wcds_parcel'] = 'WCDS_Parcel_Method';
    $methods['wcds_pallet'] = 'WCDS_Pallet_Method';
    return $methods;
});

// ─── Odśwież wysyłkę przy zmianie metody płatności (COD) ─────────────────────
add_action( 'woocommerce_checkout_update_order_review', function($post_data){
    parse_str($post_data,$data);
    if(isset($data['payment_method'])){
        WC()->session->set('chosen_payment_method', sanitize_text_field($data['payment_method']));
        WC()->cart->calculate_shipping();
    }
});

// ─── Etykieta z netto/brutto + szczegóły paczek pod metodą wysyłki ───────────
add_action( 'woocommerce_after_shipping_rate', function( $method, $index ) {
    $settings = wcds_get_settings();
    $meta     = $method->get_meta_data();

    // Opis rozkładu paczek
    if ( ! empty($meta['wcds_breakdown']) ) {
        echo '<p style="font-size:12px;color:#666;margin:4px 0 0;line-height:1.5;">';
        echo esc_html( $meta['wcds_breakdown'] );
        echo '</p>';
    }

    // Opis palety
    if ( ! empty($meta['wcds_pallet_dims']) ) {
        echo '<p style="font-size:12px;color:#666;margin:4px 0 0;line-height:1.5;">';
        echo 'Wymiary: ' . esc_html( $meta['wcds_pallet_dims'] ) . ' - jedna przesyłka, odbiór z magazynu kurierem spedycyjnym.';
        echo '</p>';
    }

    // Netto / brutto - tylko dla naszych metod i gdy opcja włączona
    if ( empty($settings['prices_are_netto']) ) return;

    $mid = $method->get_id(); // np. "wcds_parcel:1" lub "wcds_pallet:2"
    $is_ours = ( strpos($mid, 'wcds_parcel') !== false || strpos($mid, 'wcds_pallet') !== false );
    if ( ! $is_ours ) return;

    $netto = (float) $method->cost;
    if ( $netto <= 0 ) return;

    $vat        = max( 0, (float) $settings['vat_rate'] );
    $brutto     = round( $netto * ( 1 + $vat / 100 ), 2 );
    $netto_fmt  = number_format( $netto,  2, ',', ' ' );
    $brutto_fmt = number_format( $brutto, 2, ',', ' ' );

    echo '<span style="display:block;font-size:11px;color:#888;margin:2px 0 0;line-height:1.4;">';
    echo esc_html( $netto_fmt ) . ' z&lstrok; netto &mdash; ' . esc_html( $brutto_fmt ) . ' z&lstrok; brutto';
    echo '</span>';

}, 10, 2 );

// ═══════════════════════════════════════════════════════════════════════════════
// UKRYJ METODY PŁATNOŚCI INNE NIŻ COD GDY WYBRANA PRZESYŁKA POBRANIOWA
// ═══════════════════════════════════════════════════════════════════════════════
add_filter( 'woocommerce_available_payment_gateways', function( $gateways ) {

    // Działa tylko na stronie kasy
    if ( ! is_checkout() ) return $gateways;

    // Sprawdź czy klient wybrał metodę wysyłki z pobraniem
    $chosen = WC()->session ? WC()->session->get('chosen_shipping_methods', []) : [];
    $is_cod_shipping = false;

    foreach ( $chosen as $method_id ) {
        // Nasze metody pobraniowe zawierają 'cod_price' w ID
        if ( strpos( $method_id, 'cod_price' ) !== false ) {
            $is_cod_shipping = true;
            break;
        }
    }

    // Jeśli klient nie wybrał jeszcze żadnej metody wysyłki - nie filtruj
    if ( empty( $chosen ) ) return $gateways;

    if ( $is_cod_shipping ) {
        // Wybrana przesyłka pobraniowa - zostaw tylko COD, ukryj resztę
        foreach ( $gateways as $id => $gateway ) {
            if ( $id !== 'cod' ) unset( $gateways[$id] );
        }
    } else {
        // Wybrana przesyłka przedpłacona - ukryj COD, zostaw resztę
        unset( $gateways['cod'] );
    }

    return $gateways;
} );

// Wymusz odświeżenie metod płatności przez AJAX gdy zmienia się wybór wysyłki
add_action( 'wp_footer', function () {
    if ( ! is_checkout() ) return;
    ?>
    <script>
    (function($){
        // Gdy klient zmienia metodę wysyłki - odśwież sekcję płatności
        $( document.body ).on( 'change', 'input[name^="shipping_method"]', function() {
            $( document.body ).trigger( 'update_checkout' );
        });
    })(jQuery);
    </script>
    <?php
} );

// ═══════════════════════════════════════════════════════════════════════════════
// AUTOMATYCZNY WYBÓR NAJTAŃSZEJ METODY WYSYŁKI
// ═══════════════════════════════════════════════════════════════════════════════
add_filter( 'woocommerce_shipping_chosen_method', function( $default, $rates, $chosen_already ) {

    // Jeśli klient już coś wybrał wcześniej w tej sesji - nie nadpisuj
    if ( $chosen_already ) return $default;

    // Znajdź metodę z najniższą ceną
    $cheapest_id   = null;
    $cheapest_cost = PHP_INT_MAX;

    foreach ( $rates as $id => $rate ) {
        $cost = (float) $rate->cost;
        if ( $cost < $cheapest_cost ) {
            $cheapest_cost = $cost;
            $cheapest_id   = $id;
        }
    }

    return $cheapest_id ?? $default;

}, 10, 3 );
