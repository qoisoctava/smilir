# Panduan Lengkap smilir

Panduan ini membawa Anda dari instalasi pertama sampai operasi dan pembaruan.
Pilih cara menjalankan yang sesuai:

| Pilihan | Cocok untuk | Panduan rinci |
| --- | --- | --- |
| cPanel / shared hosting | PHP, MySQL, Python dan cron tersedia; tanpa akses root | [Instalasi cPanel](/docs/cpanel) |
| Docker Compose | Server/VPS atau komputer dengan Docker; database dan scheduler disertakan | [Panduan Docker](/docs/docker) |

Format DAG sama untuk kedua pilihan. Lanjutkan ke [Panduan DAG](/docs/dags)
atau [Panduan Update](/docs/updates) untuk detail khusus.

## 1. Persyaratan

- PHP 8.1+ dengan PDO MySQL dan `proc_open`.
- MySQL 5.7+ atau MariaDB 10.3+.
- Python 3.8+ untuk memuat DAG dan menjalankan task Python.
- cPanel: cron setiap menit. Docker: supercronic sudah ada di image.
- Akses HTTPS ke GitHub hanya diperlukan untuk pemeriksaan/update dari GitHub.

smilir tidak memerlukan Composer, Node, Redis, broker, atau paket Python untuk
runner-nya. `lib/dag_loader.py` dan `lib/runner.py` hanya memakai standard
library Python.

## 2. Instalasi

### cPanel

> Panduan ini memakai profil **deployment**: folder `~/pipnix`, database yang
> sudah ada, cron tidak berubah. Instalasi baru (profil **utama**) memakai
> `~/smilir` dan database baru — kodenya sama, lihat `docs/BRAND.md`.

1. Buat database dan user MySQL dari cPanel; beri user **ALL PRIVILEGES**.
2. Upload dan ekstrak paket smilir ke `~/pipnix/`. Jangan taruh `app/`, DAG,
   jobs, logs, atau konfigurasi di webroot.
3. Salin hanya isi `~/pipnix/public/` ke `~/public_html/pipnix/`.
4. Pilih PHP 8.1+ di MultiPHP Manager.
5. Salin `app/config.example.php` menjadi `app/config.php`, lalu isi bagian
   `db`, `timezone`, dan `python_bin` (contoh di bawah).
6. Jalankan installer dari Terminal/SSH:

   ```bash
   cd ~/pipnix
   php app/install.php --admin=admin:admin
   ```

   Password `admin` diterima sebagai konvensi awal, tetapi dianggap sementara:
   setelah login pertama console meminta password baru sebelum halaman lain
   bisa dibuka. Anda boleh langsung memilih password kuat dan unik, minimal
   delapan karakter:

   ```bash
   php app/install.php --admin=admin:Password-Kuat-2026
   ```

   Installer aman dijalankan ulang: ia membuat/memigrasikan tabel, dan tidak
   mereset akun jika opsi `--admin` tidak diberikan.

7. Buat virtualenv dari **Setup Python App**, lalu daftarkan di **Python →
   Register environment**. Pilih *make default* untuk environment utama.
8. Tambahkan cron scheduler setiap menit (ganti `USER` dan lokasi PHP sesuai
   akun Anda):

   ```cron
   * * * * * /usr/local/bin/php /home/USER/pipnix/app/scheduler.php >> /home/USER/pipnix/logs/cron.log 2>&1
   ```

9. Login ke `https://domain-anda/pipnix/`. Health → Scheduler seharusnya hijau
   dalam satu atau dua menit.

Lihat [langkah cPanel lengkap](/docs/cpanel) untuk konfigurasi PHP, venv,
cron, troubleshooting dan checklist keamanan.

### Docker Compose

```bash
cp .env.example .env
```

Edit `.env` sebelum boot. Ubah minimal password database; tentukan port dan
timezone. Default login awal `admin` / `admin`, lalu console memaksa perubahan
password:

```bash
docker compose up -d --build
docker compose ps
docker compose logs -f app
```

Buka <http://localhost:8080>. MySQL, logs, storage, dan venv berada dalam
named volumes; `dags/` dan `jobs/` dibind-mount dari folder proyek. Hindari
`docker compose down -v` kecuali memang ingin menghapus seluruh data.

Lihat [Panduan Docker](/docs/docker) untuk backup/restore, upgrade image,
reverse proxy/TLS, alert dan paket Python.

## 3. Konfigurasi server

### cPanel: `app/config.php`

File harus tetap berada di `~/pipnix/app/`, di luar webroot dan git. Contoh:

```php
'db' => [
    'host' => 'localhost',
    'port' => 3306,
    'name' => 'akun_pipnix',
    'user' => 'akun_pipnix',
    'pass' => 'password-database',
],
  'timezone' => 'Asia/Jakarta',
  'python_bin' => '/usr/bin/python3',
```

- `python_bin` adalah interpreter fallback untuk loader. Gunakan Python 3.8+;
  semua task Python sebaiknya memakai virtualenv terdaftar.
- `timezone` memengaruhi waktu cron/interval.
- `tick.max_launches_per_tick` membatasi proses baru per tick lintas DAG.
- Email memakai PHP `mail()`. Docker tidak menyediakan MTA/SMTP secara default.
- Retry/timeout default task, recipients alert dan retensi dapat diubah di
  **Admin → Settings**. Tick, timezone, lock dan rahasia DB tetap di config.

### Docker: `.env`

Compose membuat `app/config.php` dari variabel `PIPNIX_*`. Ubah `.env`, lalu
jalankan `docker compose up -d` agar container membaca konfigurasi baru. Jangan
commit `.env`.

### Akun dan role

| Role | Hak akses utama |
| --- | --- |
| viewer | membaca dashboard, DAG, run dan log |
| operator | trigger/pause DAG, clear task, mark-success |
| admin | seluruh akses operator, edit/import/delete DAG, Python/venv, user, settings, audit, updates |

Admin membuat user dari halaman **Admin**. Password sementara yang lemah harus
diganti pada login pertama. Beri akses admin hanya kepada orang yang dipercaya
mengubah atau menjalankan kode DAG.

## 4. Setup pertama di console

1. Login dan ganti password sementara jika diminta.
2. Buka **Python**. Register virtualenv yang sudah dibuat atau buat venv jika
   host mengizinkan. Jalankan **Check now** untuk memperbarui inventory.
3. Buat DAG di **Dags → New** atau impor `.py` melalui **Dags → Import**.
4. Periksa jadwal dan lima waktu eksekusi berikutnya di editor.
5. Simpan/validasi, lalu **Trigger**. Buka run dan task log untuk memeriksa hasil.
6. Home menampilkan health, ringkasan run/task, runner slots, favorites, dan
   kegagalan terbaru. Gunakan ☆ pada DAG untuk memasangnya ke favorites.

## 5. Membuat workflow

DAG adalah modul Python di `dags/` yang mengekspor dict bernama `DAG`:

```python
DAG = {
    "name": "daily_report",
    "schedule": "0 3 * * *",
    "venv": "main",
    "max_concurrency": 1,
    "tasks": [
        {"name": "extract", "func": "jobs.reporting.extract", "timeout": 300},
        {"name": "publish", "command": "python -m jobs.reporting publish --date {ds}",
         "depends_on": ["extract"], "retries": 2, "retry_delay": 120},
    ],
}
```

Fungsi Python disimpan di `jobs/`. Scheduler memeriksa `dags/*.py` setiap
tick; modul dimuat ulang jika SHA256 berubah. Task harus aman untuk diulang:
setiap retry menjalankan ulang fungsi/command dari awal.

- Tiap task wajib punya `name` dan tepat satu dari `func` atau `command`.
- `depends_on` menyebut nama task lain dan menentukan urutan eksekusi.
- Jadwal berupa cron lima field atau `interval:N` (menit, minimal 1).
- Cron presisi satu menit. Tick yang terlewat saat server mati tidak otomatis
  di-backfill.
- Task dapat mengatur `retries`, `retry_delay`, `timeout`, `venv`, dan
  `email_on_failure`; global defaults ada di **Admin → Settings**.
- Return value `func` yang JSON-serializable disimpan di `result_json`.
- Output `print()` dan stderr masuk ke log per-attempt.

Validasi dari terminal:

```bash
php app/dagcheck.php --file=dags/daily_report.py
```

Penjelasan seluruh keys, kontrak `func`, retry, dan contoh pola tersedia di
[Writing DAGs](/docs/dags). File contoh `dags/examples/` tidak aktif sampai
disalin ke root `dags/`.

## 6. Mengoperasikan DAG dan run

- **Trigger** membuat manual run; jadwal tetap berjalan terpisah.
- **Pause/Resume** menghentikan atau mengaktifkan run terjadwal.
- Detail DAG menyediakan **Grid, Graph, Gantt, Code**.
- Run detail membuka task instance. **Clear** mengantre ulang task dan
  downstream; **Mark success** menandai kegagalan sudah ditangani dan
  melepaskan downstream.
- **Logs** mendukung filter teks, refresh, dan download.
- **Search** global dibuka dengan `⌘K` (macOS) atau `Ctrl-K`.
- **Admin → Audit log** mencatat perubahan dan tindakan penting.

Perintah CLI umum:

```bash
php app/ops.php dags
php app/ops.php runs --dag=daily_report --n=20
php app/ops.php trigger --dag=daily_report
php app/ops.php pause --dag=daily_report
php app/ops.php resume --dag=daily_report
php app/ops.php tasks --run=12
php app/ops.php logs --run=34 --tail=100
php app/ops.php clear --run=34
php app/ops.php prune --days=30 --dry-run
```

`tasks --run` menerima ID dag-run; `clear --run` dan `logs --run` memakai ID
task-run yang ditampilkan console/CLI.

## 7. Python dan paket

Halaman **Python** mendukung register/create venv, pemeriksaan interpreter,
install/uninstall paket, upgrade paket outdated, dan upload `requirements.txt`.
Install/upgrade berjalan di background; output tersedia di pip log.

```bash
php app/ops.php venv:list                       # daftar env terdaftar
php app/ops.php venv:list --name=main           # paket di satu env
php app/ops.php venv:check --name=main
php app/ops.php venv:create --name=main --path=/home/USER/venvs/main \
    --python=/opt/alt/python311/bin/python3
php app/ops.php venv:register --name=main --path=/home/USER/venvs/main
php app/ops.php venv:install --name=main requests==2.32.3
php app/ops.php venv:upgrade --name=main
php app/ops.php venv:uninstall --name=main requests
```

`venv:create` menolak direktori yang sudah berisi; kalau venv-nya sudah ada di
disk (misalnya dibuat manual dengan `python3.11 -m venv`), pakai
`venv:register` — perintah ini sama dengan tombol **Register** di halaman
Python. Batasan versi gabungan seperti `pymysql>=1.1,<2` harus dikutip di shell
dan hanya bisa lewat CLI/`requirements.txt`, bukan form Install di UI.

Unregister environment ditolak jika masih dipakai DAG/task. Unregister tidak
menghapus virtualenv dari disk.

### Install paket lokal

`venv:install` meneruskan argumennya apa adanya ke `pip install` dengan
interpreter venv, jadi semua bentuk sumber pip bisa dipakai:

```bash
# direktori proyek, editable (kode dibaca langsung dari folder)
php app/ops.php venv:install --name=main -e /home/USER/projects/pipeline

# direktori proyek, disalin ke site-packages
php app/ops.php venv:install --name=main /home/USER/projects/pipeline

# file wheel / sdist
php app/ops.php venv:install --name=main /home/USER/wheels/pipeline-0.1.0-py3-none-any.whl

# requirements lokal (boleh berisi baris "-e /path")
php app/ops.php venv:requirements --name=main \
    --file=/home/USER/projects/pipeline/requirements.txt
```

Untuk proyek yang membaca file di samping paketnya (`.env`, `sql/`, template),
pakai `-e`: lokasi paket tetap di folder proyek, sehingga path yang dihitung
dari `__file__` tidak berpindah ke `site-packages`.

Wheel untuk server bisa dibangun di mesin lokal (tidak perlu compiler di
hosting), lalu di-upload:

```bash
python3 -m pip wheel . --no-deps -w dist          # hasil: dist/*.whl
```

```bash
~/venvs/main/bin/python -c "import pipeline; print(pipeline.__file__)"
```

Prasyarat install editable: `setuptools` (tambah `wheel` bila build isolasi
dimatikan). Pasang sekali per environment:

```bash
php app/ops.php venv:install --name=main 'pip>=24' setuptools wheel
```

Form **Install** di UI hanya menerima satu spec PyPI (`nama`, `nama==1.2.3`);
path lokal, `-e`, dan URL harus lewat terminal atau `requirements.txt`.

**Flag panjang `--…` bukan milik pip.** Parser `ops.php` menangkap setiap
argumen berawalan `--` sebagai option smilir, jadi `--no-build-isolation`,
`--upgrade`, `--force-reinstall`, `--no-index` tidak sampai ke pip. Gunakan
bentuk pendek (`-e`, `-U`, `-r`) atau panggil pip venv langsung:

```bash
~/venvs/main/bin/python -m pip install --no-build-isolation -e /home/USER/projects/pipeline
~/venvs/main/bin/python -m pip install --no-index --find-links=/home/USER/wheels requests
```

**Tanpa pip.** Setiap task sudah mendapat `~/pipnix`, `lib/`, `dags/`, dan
`jobs/` di `PYTHONPATH`, jadi menaruh paket di salah satu folder itu (atau
symlink ke sana) cukup untuk membuatnya bisa di-import:

```bash
ln -sfn /home/USER/projects/pipeline/pipeline ~/pipnix/pipeline
```

`Path(__file__).resolve()` mengikuti symlink, sehingga paket tetap membaca
`.env`/`sql/` dari folder proyek aslinya.

**`pip exited with -1`.** Pada PHP < 8.3, `proc_close()` mengembalikan `-1`
setelah `proc_get_status()` dipanggil (PHP bug #75272), walaupun pip sukses.
Smilir memakai exit code dari `proc_get_status()`, jadi pesan ini hanya muncul
di build lama. Patokan keberhasilan tetap baris `Successfully installed …`
atau inventory dari `venv:list --name=…`.

## 8. Settings, alert dan retensi

**Admin → Settings** mengatur global task defaults (retries, retry delay,
timeout, email-on-failure), alert enabled/from/recipients, retensi dan purge
manual. Defaults diterapkan saat DAG dimuat ulang pada tick berikutnya. Purge
menghapus run selesai dan log yang lebih lama dari batas hari; audit tetap
tersimpan.

SMTP editor belum tersedia: alert memakai PHP `mail()`. Untuk Docker, siapkan
relay sendiri jika email diperlukan. Gunakan alamat `From` yang diterima host.

## 9. Backup dan pemulihan

Backup **database**, `logs/`, `storage/`, serta source pipeline `dags/` dan
`jobs/` secara berkala. Simpan DAG/job di git atau backup privat.

```bash
mysqldump --single-transaction -u USER -p DATABASE > pipnix-$(date +%F).sql
```

Untuk Docker, ikuti prosedur backup dan restore di [Panduan Docker](/docs/docker).
Jangan masukkan `app/config.php` atau `.env` ke repo publik.

## 10. Memperbarui

### Docker

```bash
docker compose up -d --build
```

Volume data tetap ada; entrypoint menjalankan migrasi idempoten. **Check now**
boleh digunakan untuk membaca versi GitHub, tetapi apply/rollback file
nonaktif di Docker secara default karena perubahan layer hilang saat rebuild.

### cPanel

**Penting:** layout cPanel yang didokumentasikan menyalin isi `public/` ke
`public_html/pipnix/`. Updater saat ini memperbarui source `~/pipnix/public/`,
bukan salinan aktif di `public_html`. Jadi jangan mengandalkan tombol
**Update now** untuk memperbarui keseluruhan UI pada layout salin-terpisah.

Untuk layout tersebut, update manual: backup database dan file, perbarui source
privat sambil mempertahankan `app/config.php`, `dags/`, `jobs/`, `logs/`, dan
`storage/`; jalankan `php app/install.php`; lalu salin isi `~/pipnix/public/`
terbaru ke `~/public_html/pipnix/`. Jika document root langsung menunjuk ke
`~/pipnix/public/` (atau symlink didukung), updater dapat mengganti webroot dan
source dalam satu lokasi.

Lihat [Panduan Update](/docs/updates) untuk channel branch/release, backup,
rollback, permission, dan GitHub.

## 11. Batasan dan troubleshooting

- Scheduler berjalan tiap menit; tidak ada backfill otomatis setelah downtime.
- Eksekusi single-host dan low-parallelism; bukan pengganti Airflow skala besar.
  DAG dan task adalah kode tepercaya, tidak disandbox.
- Scheduler merah: periksa cron dan `logs/cron.log` (cPanel), atau
  `docker compose logs app` (Docker).
- Run tidak mulai: cek DAG **Active**, schedule, venv, `Next Run`, lalu jalankan
  `php app/scheduler.php` secara manual.
- `runner did not report`: host mungkin memblokir `proc_open`/proses detached,
  atau timeout terlalu singkat.
- 419: reload halaman untuk mendapatkan session/CSRF token baru.
- Tombol web menggantung lalu berakhir `timed out after 60s` (buat/cek/hapus
  env Python, install paket): domain memakai PHP-FPM atau LiteSpeed sehingga
  `PHP_BINARY` menunjuk `php-fpm`/`lsphp`. Set
  `'php_bin' => '/opt/cpanel/ea-php82/root/usr/bin/php'` di `app/config.php`
  (smilir memeriksa kandidat dan hanya menerima PHP >= 8.1).
- Log pip berisi `Unsupported declare 'strict_types'` atau `parse error,
  unexpected '?'`: proses yang di-spawn adalah PHP 5.x (`lsphp` lama) — sama
  seperti kasus di atas, arahkan `php_bin` ke PHP 8.x.
- `ensurepip is not available` saat membuat env: `/usr/bin/python3` di cPanel
  adalah 3.6. Arahkan `python_bin` ke path absolut alt-python, mis.
  `/opt/alt/python311/bin/python3.11`.
- Update gagal permission: PHP perlu hak tulis ke direktori kode terkait.
- Login pertama memakai password lemah? Ganti password sebelum melanjutkan.

Troubleshooting khusus tersedia di akhir panduan cPanel, Docker, dan Update.
