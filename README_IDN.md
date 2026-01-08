
---

![App Content Manager Screenshot](1.png)

# appDocs (App Content Manager)

PHP MySQL

## Gambaran Umum

App Content Manager adalah tools dokumentasi internal sekaligus snapshot code berbasis PHP dan MySQL. Tools ini menyimpan source code aplikasi ke dalam database lalu menghasilkan dokumentasi ramah cetak yang dapat diekspor ke PDF.

PDF hasil export dirancang khusus agar mudah dipahami oleh AI code generator. AI dapat membaca struktur codebase asli, batas modul, pola implementasi, serta arsitektur aplikasi secara nyata.

Tools ini cocok untuk codebase besar, sistem legacy, monorepo, serta lingkungan multi aplikasi.

---

## Cara Kerja appDocs

Dokumentasi Sederhana yang Mengikuti Proses Coding

appDocs dibuat agar dokumentasi tidak mengganggu proses development. Dokumentasi berjalan bersamaan dengan aktivitas coding sehari hari.

Alur kerjanya ringkas
developer tetap coding di codebase asli
appDocs menyimpan snapshot terpilih ke database
PDF dokumentasi dapat dibuat kapan saja

---

## Konsep Inti

appDocs bukan editor code.
appDocs bukan version control system.

appDocs berperan sebagai **lapisan snapshot dokumentasi** di atas codebase yang sudah ada.

Yang disimpan oleh appDocs
snapshot source code
metadata file
struktur aplikasi serta modul

Yang tidak pernah disentuh
file fisik
repository git
lingkungan deployment

---

## Alur Kerja Bertahap

### 1. Coding Seperti Biasa

Developer tetap bekerja menggunakan IDE serta repository yang sama.
Tidak ada perubahan workflow.

---

### 2. Menyalin Code Fisik ke Snapshot Content

Saat sebuah file dianggap penting untuk dokumentasi, refactoring, atau konteks AI, developer menyalin isi file dari codebase fisik ke appDocs.

Pada tahap ini
isi file disimpan ke database
path file dicatat
file dihubungkan ke App serta Module

Hanya file terpilih yang didokumentasikan.
Dokumentasi menjadi fokus serta relevan.

---

### 3. Sinkronisasi Perubahan Code

Saat code fisik berubah, developer menjalankan fitur **Sync Code**.

Proses sync mencakup
validasi keberadaan file
pembaruan metadata ukuran serta waktu modifikasi
indikasi perubahan file

Jika file berubah, developer memperbarui snapshot dengan menyalin versi terbaru ke appDocs.

Tidak ada penulisan ke filesystem.
Seluruh perubahan terjadi di database.

---

### 4. Snapshot Selalu Siap Diekspor

Setiap snapshot yang tersimpan langsung menjadi bagian dari dokumentasi.

Tidak perlu build
tidak perlu compile
tidak perlu tools tambahan

Status dokumentasi selalu mengikuti snapshot terbaru.

---

### 5. Generate PDF Kapan Saja

Saat dibutuhkan, appDocs menyusun tampilan dokumentasi ramah cetak berdasarkan snapshot database.

Isi dokumen meliputi
struktur App serta Module
daftar file
snapshot source code
ringkasan hasil AI

PDF dibuat melalui browser print
Ctrl P
Save as PDF

Hasilnya berupa dokumen statis konsisten siap dipakai sebagai konteks AI.

---

## Alasan Pendekatan Ini Efektif

### Dokumentasi Mengikuti Ritme Developer

Hanya file bermakna yang didokumentasikan.
Tidak ada tekanan mendokumentasikan seluruh codebase.

---

### Snapshot Dibuat Secara Sadar

Snapshot dibuat berdasarkan keputusan developer, bukan proses otomatis.

Dokumentasi menjadi
bersih
fokus
ramah AI

---

### Aman di Semua Lingkungan

Karena tidak memodifikasi file fisik, appDocs aman digunakan di
local
staging
production

---

## Ringkasan Alur Kerja

Coding tetap berjalan
Salin file penting ke snapshot database
Jalankan sync untuk validasi
Perbarui snapshot saat code berubah
Generate PDF kapan pun diperlukan

---

Dengan pendekatan ini, dokumentasi menjadi hasil samping alami dari proses development.
appDocs mengubah aktivitas coding harian menjadi dokumentasi terstruktur siap dipahami AI tanpa friksi.

---

## Tujuan Utama

* Sentralisasi dokumentasi aplikasi
* Menyimpan snapshot code tanpa menyentuh file fisik
* Menyediakan konteks terstruktur untuk AI code generator
* Mengurangi halusinasi AI saat generate code
* Meningkatkan akurasi refactoring sistem legacy
* Mempercepat proses onboarding developer

---

## Fitur Utama

### Manajemen Aplikasi

* Create Read Update Delete App
* Penentuan root path aplikasi
* Validasi keberadaan file di filesystem
* Penyimpanan metadata ukuran waktu modifikasi hash

### Manajemen Modul

* Create Read Update Delete Modul per App
* Pengelompokan file berdasarkan tanggung jawab
* Representasi layer arsitektur

### Manajemen Konten Code

* Create Read Update Delete konten code
* Relasi App Modul serta File Path
* Penyimpanan source code ke database
* Penyimpanan ringkasan hasil AI
* Tampilan detail file

### Browsing serta Filtering

* Melihat seluruh konten tersimpan
* Filter berdasarkan App
* Filter berdasarkan Modul
* Berguna untuk audit serta eksplorasi code

### Export Dokumentasi

* Export ke HTML ramah cetak
* Simpan sebagai PDF melalui browser
* Tanpa library PDF pihak ketiga
* Dioptimalkan sebagai dokumen konteks AI

---

## Konsep Arsitektur Data

* **App**
  Representasi satu aplikasi atau service
  Memiliki root path terdefinisi

* **Module**
  Kelompok logis dalam App
  Contoh authentication billing reporting

* **Content**
  Snapshot file
  Path file judul source code ringkasan

Seluruh data disimpan di database.
File fisik tidak pernah dimodifikasi.

---

## Peran PDF dalam AI Code Generation

PDF hasil export berfungsi sebagai **paket konteks AI**.

Isi dokumen mencakup
struktur direktori
relasi App Modul serta Content
snapshot source code
ringkasan AI per file

Manfaat langsung
AI memahami struktur codebase
AI mengikuti konvensi penamaan
AI menghormati pola arsitektur
AI menghasilkan patch lebih relevan
AI bekerja lebih aman pada sistem legacy

PDF dapat diunggah ke
ChatGPT
Claude
Gemini
AI internal perusahaan

---

## Instalasi

### Kebutuhan Sistem

* PHP 7.4 atau lebih baru
* MySQL atau MariaDB
* Web server Apache atau Nginx

### Langkah Instalasi

1. Buat database MySQL bernama `appdocs`
2. Import file `schema.sql`
3. Atur kredensial database di `config/db.php`
4. Letakkan folder project di web root
5. Buka `public/index.php` melalui browser

---

## Struktur Project

```
appdocs/
├── config/
│   └── db.php
├── lib/
│   └── helpers.php
├── public/
│   ├── index.php
│   ├── apps.php
│   ├── modules.php
│   ├── contents.php
│   ├── export_pdf.php
│   └── exports.php
├── schema.sql
└── README.md
```

---

## Cara Kerja Export PDF

1. Buka menu Export
2. Terapkan filter bila perlu
3. Halaman print friendly terbuka
4. Tekan Ctrl P
5. Pilih Save as PDF

PDF siap digunakan sebagai konteks AI.

---

![PDF print Screenshot](2.png)

---

## Catatan Penting

* Tidak menggunakan library PDF pihak ketiga
* Seluruh export bergantung pada browser print
* Edit konten hanya memengaruhi database
* Tidak ada operasi tulis ke filesystem
* Aman digunakan pada codebase production

---

## Contoh Penggunaan Ideal

* Dokumentasi engineering internal
* Migrasi sistem legacy
* Refactoring berbantuan AI
* Workflow development berbasis AI
* Basis pengetahuan teknis

---

## Lisensi

Penggunaan internal
Sesuaikan dengan kebijakan organisasi

## Kredit
Dikembangkan oleh Kukuh Tripamungkas Wicaksono (Kukuh TW) https://www.linkedin.com/in/kukuhtw/ 2025 - 2026
