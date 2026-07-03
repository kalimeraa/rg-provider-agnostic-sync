# Veri Senkronizasyon Sistemi - Technical Case

## Genel Bakış
Bir e-ticaret platformu için 3rd party tedarikçi API'sinden ürün verilerini senkronize eden bir sistem geliştirmeniz bekleniyor. Sistem, periyodik olarak API'den veri çekecek, değişiklikleri tespit edecek ve local database'i güncel tutacak.

## Senaryo
Şirketiniz, birden fazla tedarikçiden ürün bilgilerini topluyor. Tedarikçiler kendi API'leri üzerinden ürün bilgilerini (stok, fiyat, açıklama) güncelliyor. Sizin göreviniz bu verileri güvenilir bir şekilde senkronize etmek.

## Mock API Servers 

1- DummyJSON: https://dummyjson.com/products

2- FakeStore API: https://fakestoreapi.com/products

100 adet ürün verisi

GET /products - Tüm ürünler

GET /products/{id} - Tek ürün

Rate limit simulation için kendi throttling'inizi ekleyebilirsiniz

## Teknik Gereksinimler

### 1. Backend (PHP)

**Framework tercihi size kalmış** 

#### Zorunlu Özellikler:

**A. Senkronizasyon Queue Sistemi**
- Her 5-10 dakikada bir otomatik senkronizasyon (cron job veya scheduler)
- API'den veri çekme işlemleri queue'ya atılmalı
- Failed job handling ve retry mekanizması (max 3 deneme)
- Exponential backoff stratejisi (örn: 1s, 2s, 4s)
- **Job uniqueness:** Provider bazında aynı anda sadece 1 sync job çalışmalı. Aynı provider için yeni sync başlatılırken aktif job varsa, yeni job başlatılmamalı veya queue'da beklemeli.
  - Örnek senaryo: DummyJSON sync'i çalışırken, yeni bir DummyJSON sync job'ı başlatılmamalı
  - Çözüm önerinizi dokümante edin (lock mekanizması, database flag, cache key vb.)

**B. Delta Sync (Hash-based)**
- Sadece değişen kayıtları güncelleme
- Hash bazlı change detection (MD5 veya SHA256 kullanarak)
- Ürün datasının hash'ini hesaplayıp database'de sakla
- Yeni API datasının hash'i ile karşılaştır
- Sadece hash farklı ise güncelleme yap
- Yeni eklenen ve silinen ürünleri tespit etme

**C. Rate Limiting & Throttling**
- API rate limit'e takılmadan çalışma (saniyede max 5 request olarak varsayın)
- Request'ler arası delay ekleyerek throttling sağlayın
- 429 (Too Many Requests) response gelirse exponential backoff uygulayın
- API'den sürekli hata alınırsa (örn: 5 ardışık başarısız request) sync durdurup error log'layın

**D. Dead Letter Queue**
- 3 denemeden sonra başarısız olan job'lar için DLQ
- Manuel retry mekanizması

**F. API Endpoints**
```
POST   /api/sync/trigger        - Manuel senkronizasyon başlat
GET    /api/sync/status         - Aktif sync durumu
GET    /api/sync/history        - Geçmiş sync logları (pagination)
GET    /api/sync/failed-jobs    - Başarısız job'lar
POST   /api/sync/retry/{jobId}  - Failed job'ı retry et
GET    /api/products            - Local ürün listesi (pagination)
GET    /api/health              - Sistem health check
```

**API Response Format:**

Tüm endpoint'ler için standart JSON response format kullanın:

```json
{
  "success": true,
  "data": {},
  "meta": {
    "page": 1,
    "per_page": 20,
    "total": 100
  },
  "message": "İşlem başarılı"
}
```

Hata durumunda:
```json
{
  "success": false,
  "error": {
    "code": "SYNC_FAILED",
    "message": "Senkronizasyon başarısız oldu"
  }
}
```

Pagination kullanan endpoint'ler için `meta` alanı zorunludur.

**E. Database Schema**

Aşağıdaki tablolar için database tasarımı yapmanız bekleniyor. Migration dosyaları oluşturun veya SQL dump sağlayın.

```
-- products tablosu
id, provider_type, external_id, name, price, stock,
description, data_hash, last_synced_at, created_at, updated_at

-- sync_logs tablosu
id, provider_type, started_at, completed_at, status,
products_added, products_updated, products_deleted,
error_message, created_at

-- failed_jobs tablosu
id, job_type, payload, exception, failed_at, retry_count
```

**Beklentiler:**
- Uygun data type seçimi (VARCHAR, INT, DECIMAL, TEXT, JSON, TIMESTAMP vb.)
- Primary key, foreign key ve unique constraint'ler
- Performans için gerekli index'ler
- Migration dosyaları veya SQL dump

**Önemli:** Hash calculation stratejinizi dokümante edin (hangi alanlar hash'e dahil, nasıl hesaplanıyor).

**G. Teknik Beklentiler**

**Idempotency (Zorunlu):**
- Aynı sync job birden fazla çalıştırılsa bile sonuç aynı olmalı
- Duplicate kayıt oluşmamalı
- Örnek senaryo: Job yarıda kesilip tekrar çalışırsa, aynı ürünler tekrar eklenmemeli
- Çözüm önerinizi README'de açıklayın (unique constraint, upsert, transaction vb.)

**Test Coverage:**
- Minimum %70 unit test coverage bekleniyor
- Mutlaka test edilmesi gereken senaryolar:
  - Hash calculation doğruluğu
  - Delta sync (yeni, güncellenen, değişmeyen ürünler)
  - Retry mekanizması ve exponential backoff
  - Rate limiting ve throttling
  - Idempotency (aynı job 2 kez çalışırsa)
  - API failure handling
- Integration test opsiyonel ancak artı puan

**Diğer Beklentiler:**
- Detailed logging (sync başlangıç/bitiş, hata detayları, performance metrics)
- Clean architecture ve separation of concerns
- SOLID principles
- Error handling ve exception management
- README'de tüm kararlarınızı dokümante edin


## 2. Frontend (Basit & Minimal)
Tek sayfalık basit bir dashboard yeterli. Teknoloji seçimi size kalmış - Vue.js, React, Vanilla JS veya başka bir teknoloji kullanabilirsiniz.
### Minimum Gereksinimler: ###

**A. Basit Dashboard (Tek Sayfa)**

- Manuel sync trigger butonu
- Son sync durumu ve istatistikleri (tarih, süre, sonuç)
- Eklenen/güncellenen/silinen ürün sayıları
- Basit sync history tablosu (son 10 kayıt)
- Failed jobs listesi ve retry butonları
- Auto-refresh
- Basit responsive layout (mobile uyumlu)

**Beklenen Layout:**

- Header: Sync Dashboard title
- Status section: Son sync bilgisi, istatistikler, manuel trigger butonu
- History section: Basit tablo formatında geçmiş sync'ler

Failed jobs section: Hatalı job'lar ve retry butonları


**Styling:**

Tailwind CSS, Bootstrap veya custom CSS kullanabilirsiniz
Süslü animasyon beklenmez, fonksiyonel olması yeterli


## Bonus Özellikler ##
**1. Multiple Provider Support**
Açıklama: Sistemin farklı tedarikçi API'lerini desteklemesi
Beklentiler:

- 2 farklı provider implementasyonu (DummyJSON + FakeStoreAPI vb)

- Her provider için ayrı configuration

- Provider bazlı sync job'ları

**2. Docker Setup**
Beklentiler:

- docker-compose.yml ile tek komutla ayağa kalkma
- Separate container'lar: app, database, cache (redis vb), queue-worker
- README'de docker setup instructions

**Minimum Container'lar:**

- Web server container (nginx/apache + php)
- Database container (MySQL/PostgreSQL)
- Cache/Queue container (Redis)

**3. Alerting System**

Açıklama: Critical durumlar için alerting mekanizması

**Alert Scenarios:**

- Sync 3 kere üst üste başarısız olursa
- Failed job sayısı 10'u geçerse
- API'den sürekli hata alınıyorsa (5+ ardışık başarısız request)
- Queue'da bekleyen job sayısı 100'ü geçerse

**Implementation Beklentisi:**

En az aşağıdakilerden birini implement edin:

1. **Structured Logging (Minimum):**
   - Alert seviyesinde log entry oluştur (LOG_LEVEL=ALERT veya CRITICAL)
   - JSON format ile structured log (alert_type, severity, timestamp, details)
   - Örnek: `{"level": "ALERT", "type": "SYNC_FAILURE", "count": 3, "provider": "dummyjson"}`
   - Bu loglar kolayca parse edilip monitoring tool'a gönderilebilir

2. **Notification (Tercih Edilen):**
   - Email, Slack, Discord webhook vb.
   - Alert threshold ayarlanabilir olmalı (config/env'den)
   - Rate limiting: Aynı alert 5 dakikada 1 kez gönderilmeli

Alerting stratejinizi README'de açıklayın.


### Gereksinimler
- PHP 8.x
- MySQL/PostgreSQL
- Redis (veya alternatif)
- Composer
## API Documentation
Endpoint'lerin listesi ve kullanımı

## Bonus Features (varsa)
- Multiple provider support açıklaması
- Docker setup
- Alerting mekanizması

### 3. Configuration
- `.env.example` veya `config.example.php` dosyası
- Tüm gerekli environment variables açıklamalı olmalı
- Database credentials, API URLs, Queue settings vb

### 4. Database
- Migration files (veya SQL dump)
- Seed data (opsiyonel)

### 5. Tests
- Unit test files
- Test koşma talimatları

### 6. Postman Collection (Opsiyonel)
- API endpoint'lerini test edebilmek için

## Önemli Notlar ##

**Framework Kullanımı:** Framework kullanmak zorunlu değildir. 

**Queue Implementation:** Hazır bir queue library kullanabilir (ör: Laravel Queue, Symfony Messenger) veya kendiniz implement edebilirsiniz.

**Hash Calculation:** Ürün datasını serialize edip MD5/SHA256 hash'i alınız. Bu hash'i database'de saklayıp karşılaştırarak değişiklikleri tespit ediniz.

**Code Quality:** Clean code, SOLID principles ve design patterns kullanımı değerlendirilecektir.

**Testing:** En az happy path ve error scenario'ları için test yazılmalıdır.

**Documentation:** README'de kurulum adımları açık ve anlaşılır olmalıdır. Projeyi 5 dakikada ayağa kaldırabilmeliyiz.

**Bonus Features:** Bonus özellikler zorunlu değildir ancak artı puan getirir.


## Teslim Süreci ##

**Teslim Formatı:**
- Fork ettiğiniz GitHub repository linki
- README.md'de kurulum adımları (5 dakikada çalıştırılabilmeli)
- Çalışan uygulama (local'de test edilmiş olmalı)

**README.md İçermesi Gerekenler:**
- Kurulum adımları (dependencies, database setup, env config)
- Çalıştırma komutları (server, queue worker, cron setup)
- Test koşma talimatları
- Aldığınız teknik kararlar ve gerekçeleri:
  - Database tasarımı (index, constraint vb.)
  - Hash calculation stratejisi
  - Job uniqueness implementasyonu
  - Idempotency çözümü
  - Rate limiting yaklaşımı
- API endpoint dokümantasyonu (veya Postman collection)
- Bonus özellikler (varsa)

## Değerlendirme Kriterleri ##

**1. Fonksiyonellik (40%)**
- Sync işlemleri doğru çalışıyor mu?
- Delta sync hash-based olarak çalışıyor mu?
- Queue ve retry mekanizması doğru mu?
- API endpoint'ler düzgün response dönüyor mu?
- Error handling yeterli mi?

**2. Code Quality (30%)**
- Clean code principles
- SOLID principles
- Design patterns kullanımı
- Separation of concerns
- Code organization ve structure

**3. Testing (15%)**
- Test coverage (%70+ bekleniyor)
- Kritik senaryolar test edilmiş mi?
- Test quality (meaningful tests)

**4. Documentation (10%)**
- README açıklayıcı mı?
- Kurulum kolay mı?
- Teknik kararlar dokümante edilmiş mi?
- API dokümantasyonu var mı?

**5. Database Tasarımı (5%)**
- Uygun data type seçimi
- Index stratejisi
- Constraint'ler doğru mu?
- Migration quality

**Bonus Puanlar:**
- Docker setup (+10%)
- Multiple provider support (+10%)
- Alerting system (+5%)
- Integration/E2E tests (+5%)
- Exceptional code quality (+5%)

## Sorular? ##
Case ile ilgili teknik sorularınız varsa lütfen iletişime geçin. Başarılar!
