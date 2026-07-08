# WC Dimension Shipping

Wtyczka WooCommerce do wysyłki wymiarowej. Koszt przesyłki liczony jest na podstawie fizycznych wymiarów produktów w koszyku, a nie samej wagi czy sumy objętości - plugin próbuje realnie upakować produkty w dostępne rozmiary paczek (algorytm 3D bin-packing z uwzględnieniem wszystkich orientacji produktu).

## Jak to działa

- W panelu admina definiujesz dowolną liczbę rozmiarów paczek (długość, szerokość, wysokość, maks. waga, cena) oraz opcjonalną przesyłkę paletową jako stałą stawkę.
- Dla każdego zamówienia plugin sprawdza, ile sztuk każdego produktu fizycznie mieści się w danym pudełku (testując 6 możliwych orientacji), a następnie szuka najtańszej kombinacji paczek pokrywającej cały koszyk.
- Jeśli w koszyku znajdzie się produkt, który nie mieści się w żadnej zdefiniowanej paczce, metody paczkowe znikają z kasy - klient widzi tylko opcję palety.
- Każda metoda (paczka, paleta) występuje w dwóch wariantach: przedpłata i pobranie, z osobnymi cenami.
- Wybór dostawy za pobraniem automatycznie ogranicza metody płatności do COD, a wybór przedpłaty ukrywa COD.
- Domyślnie zaznaczana jest najtańsza dostępna opcja wysyłki.
- Opcjonalnie: ceny w pluginie mogą być cenami netto - wtedy pod każdą stawką w kasie doliczane i wyświetlane jest brutto wg zadanej stawki VAT.

Cała konfiguracja odbywa się z panelu WooCommerce -> Paczki wymiarowe, bez ingerencji w kod.

## Wymagania

- WordPress
- WooCommerce (aktywny i skonfigurowany)
- PHP 8.0 lub nowszy (kod korzysta z typowania właściwości i union types)

## Instalacja

1. Skopiuj plik `wc-dimension-shipping.php` do katalogu `wp-content/plugins/wc-dimension-shipping/`.
2. Aktywuj wtyczkę w panelu WordPress.
3. Przejdź do WooCommerce -> Paczki wymiarowe i skonfiguruj rozmiary paczek oraz paletę.
4. W ustawieniach stref wysyłki WooCommerce dodaj metody "Wysyłka paczkowa (wg wymiarów)" i "Przesyłka paletowa" do właściwych stref.
5. Uzupełnij wymiary i wagę w kartach produktów - bez tych danych plugin nie ma na czym liczyć.

## Konfiguracja

W panelu WooCommerce -> Paczki wymiarowe:

- **Rozmiary paczek** - dowolna liczba wpisów, każdy z własnymi wymiarami (cm), limitem wagi (kg) i cenami dla przedpłaty oraz pobrania.
- **Przesyłka paletowa** - włącznik, wymiary maksymalne, limit wagi i ceny. Traktowana jako stała stawka niezależnie od zawartości.
- **Ustawienia ogólne** - przełącznik "ceny są netto" i stawka VAT, jeśli klientom ma być pokazywana kwota brutto.

## Ograniczenia

- Algorytm pakowania to przybliżenie (heurystyka najlepszego dopasowania), nie pełny optymalny bin-packing 3D - dla bardzo dużych i zróżnicowanych koszyków może nie znaleźć teoretycznie najtańszej kombinacji, ale w praktyce daje wynik bliski optymalnemu przy rozsądnym czasie obliczeń.
- Wymaga uzupełnionych wymiarów i wagi w każdym produkcie - produkty bez tych danych są traktowane jako mające minimalne wymiary/wagę.

## Licencja

GPL-2.0-or-later - zobacz plik [LICENSE](LICENSE).

## Autor

Jakub Skorupa - [skorupa.net.pl](https://skorupa.net.pl/)
