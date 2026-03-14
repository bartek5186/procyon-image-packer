<p align="center">
  <img src="./proycon-image-packer.png" alt="Procyon Image Packer" width="360" />
</p>

# Procyon Image Packer

Batchowy optymalizator obrazow dla attachmentow WordPressa, uruchamiany przez zewnetrzny runner shellowy.

Plugin nie probuje budowac od zera wlasnego systemu responsive images. Korzysta z metadanych attachmentow WordPressa, istniejacych sub-size'ow i opcjonalnego przepisywania URL-i na `webp` / `avif`.

## Co robi ten plugin

- skanuje attachmenty z Media Library dla obslugiwanych formatow wejsciowych: `image/jpeg` i `image/png`,
- opcjonalnie naprawia brakujace sub-size'y WordPressa przed przetwarzaniem,
- buduje manifest plikow do obrobki na bazie oryginalow i wygenerowanych sub-size'ow,
- uruchamia ciezki batch poza WordPressem,
- optymalizuje oryginaly przez `jpegoptim` i `pngquant`,
- generuje pliki sasiednie `webp` przez `cwebp`,
- opcjonalnie generuje pliki sasiednie `avif` przez `avifenc`,
- raportuje postep, liczbe przetworzonych plikow, bledy i aktualny plik,
- udostepnia sterowanie jobem przez panel admina, REST API i WP-CLI,
- opcjonalnie podmienia URL-e attachmentow i kandydatow `srcset` na `avif` / `webp` na froncie.

## Wymagania

- WordPress z attachmentami w Media Library,
- PHP 7.4+,
- serwer pozwala uruchamiac background shell z PHP przez `exec`, `shell_exec` lub `popen`,
- zapisywalny katalog uploads,
- narzedzia systemowe zaleznie od wlaczonych funkcji:
  - `jpegoptim` dla optymalizacji oryginalow JPEG,
  - `pngquant` dla optymalizacji oryginalow PNG,
  - `cwebp` dla generowania WebP,
  - `avifenc` dla generowania AVIF.

Przykladowe komendy instalacyjne pokazywane tez w UI pluginu:

```bash
sudo apt update && sudo apt install jpegoptim
sudo apt update && sudo apt install pngquant
sudo apt update && sudo apt install webp
sudo apt update && sudo apt install libavif-bin
```

## Szybki start

1. Aktywuj plugin.
2. Wejdz do **Media -> Procyon Image Packer**.
3. Sprawdz wykryte binarki i doinstaluj brakujace elementy dla opcji, ktore chcesz wlaczyc.
4. Skonfiguruj:
   - optymalizacje oryginalow,
   - generowanie WebP,
   - generowanie AVIF,
   - przepisywanie URL-i na froncie,
   - naprawianie brakujacych sub-size'ow,
   - automatyczna kolejke dirty-run dla nowych uploadow.
5. Uruchom pelny batch.
6. Monitoruj postep w panelu, REST albo przez WP-CLI.

## Panel admina

`Media -> Procyon Image Packer`

Widok admina pokazuje:

- aktualny status joba,
- procent postepu,
- liczbe przetworzonych plikow,
- liczniki sukcesow i bledow,
- sciezke aktualnego pliku,
- liczbe przeskanowanych attachmentow,
- liczbe attachmentow w kolejce,
- brakujace narzedzia systemowe wraz z komendami instalacji,
- kontrolki `Start`, `Pause`, `Resume` i reczne odswiezenie.

## Flow przetwarzania

### Nowe uploady

Plugin podpina sie pod `wp_generate_attachment_metadata`.

Dla obslugiwanych typow wejsciowych:

- oznacza attachment jako dirty,
- opcjonalnie planuje opozniony dirty-run,
- traktuje metadane i sub-size'y WordPressa jako zrodlo prawdy.

### Pelny lub dirty batch

Przy starcie batcha plugin:

1. czyta ustawienia pluginu,
2. waliduje wymagane binarki dla wlaczonych funkcji,
3. pobiera obslugiwane attachmenty z WordPressa,
4. opcjonalnie naprawia brakujace image sub-sizes przez API core,
5. buduje `manifest.tsv` dla plikow, ktore nie sa jeszcze aktualne,
6. uruchamia w tle `bin/process-images.sh`,
7. czyta pliki runtime i raportuje zywy postep,
8. synchronizuje wyniki z powrotem do rejestru pluginu i meta attachmentow.

### Runner shellowy

Runner shellowy przetwarza fizyczne pliki po kolei i zapisuje pliki stanu w trakcie pracy.

Dla kazdego pliku moze:

- zoptymalizowac oryginal,
- utworzyc sasiedni `.webp`,
- utworzyc sasiedni `.avif`,
- dopisac rekord sukcesu albo bledu,
- zatrzymac sie czysto po zadaniu pauzy,
- po resume kontynuowac od pozostalych wpisow manifestu.

## Pliki wynikowe

Plugin tworzy pliki sasiednie obok oryginalnych plikow obrazow, na przyklad:

```text
photo.jpg
photo-300x200.jpg
photo.webp
photo-300x200.webp
photo.avif
photo-300x200.avif
```

## Pliki stanu

Stan batcha jest trzymany w:

```text
wp-content/uploads/procyon-image-packer/
```

Najwazniejsze pliki:

- `job.json` metadane aktualnego joba,
- `job.env` wyeksportowane srodowisko shellowe dla runnera,
- `manifest.tsv` kolejka plikow do przetworzenia,
- `runtime.status` liczniki live,
- `done.tsv` rekordy sukcesow,
- `failed.tsv` rekordy bledow,
- `registry.tsv` znane sygnatury juz przetworzonych plikow,
- `job.log` log wyjscia shellowego,
- `pause.flag` znacznik prosby o pauze,
- `runner.lock` lock aktywnego runnera.

## Zachowanie na froncie

Plugin nie buduje wlasnej bazy `srcset`.

Zamiast tego opiera sie o renderowanie attachmentow przez WordPress:

- `wp_get_attachment_url()`,
- `wp_get_attachment_image_src()`,
- `wp_calculate_image_srcset()`.

Jesli wlaczone jest przepisywanie URL-i na froncie, plugin:

- preferuje `avif`, gdy przegladarka deklaruje obsluge w `Accept` i istnieje plik `.avif`,
- w przeciwnym razie preferuje `webp`, gdy przegladarka deklaruje obsluge w `Accept` i istnieje plik `.webp`,
- w pozostalych przypadkach zostawia URL oryginalnego pliku.

## REST API

Namespace: `procyon-image-packer/v1`

Wszystkie endpointy wymagaja uzytkownika z uprawnieniem `manage_options`.

### Status

- `GET /wp-json/procyon-image-packer/v1/status`

Zwraca:

- metadane joba,
- liczniki postepu,
- aktualne ustawienia,
- wykryte binarki,
- bledy i warningi srodowiska,
- flagi UI takie jak `can_start`, `can_pause`, `can_resume`.

### Start

- `POST /wp-json/procyon-image-packer/v1/start`

Parametry body:

- `mode` = `full` albo `dirty`

### Pause

- `POST /wp-json/procyon-image-packer/v1/pause`

### Resume

- `POST /wp-json/procyon-image-packer/v1/resume`

## WP-CLI

```bash
wp procyon image-packer status
wp procyon image-packer start --mode=full
wp procyon image-packer start --mode=dirty
wp procyon image-packer pause
wp procyon image-packer resume
```

## Uwagi

- Obsluga wejscia jest na start ograniczona do JPEG i PNG.
- Batch nie wystartuje, jesli dla wlaczonych funkcji brakuje wymaganej binarki.
- Plugin preferuje metadane obrazow i API sub-size WordPress core zamiast wlasnej logiki rozmiarow.
- Runner shellowy przetwarza pliki poza WordPressem, ale kolejka jest budowana z attachmentow, a nie z surowego slepego skanu filesystemu.
- Gdy attachment zostanie usuniety z Media Library, plugin usuwa tez sasiednie pliki `.webp` / `.avif` oraz wpisy swojego rejestru.

## Licencja

Ten projekt jest open source i jest licencjonowany na zasadach **GNU GPL v2 lub nowsza** (`GPL-2.0-or-later`).
