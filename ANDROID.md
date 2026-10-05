# AH5 Office — Android অ্যাপ পরিকল্পনা ও API চুক্তি

এই ডকুমেন্ট অ্যাপ বানানোর আগে ঠিক করে রাখার জিনিসগুলো ধরে রাখে।
ব্যাকএন্ডে নতুন কিছু লাগবে না — অ্যাপ একই REST API ব্যবহার করবে।

**Designed & Developed by [Anwar Hossain](https://anwar.com.bd) · [Creatives iT](https://creativesit.com)**

---

## ১. অ্যাপটা আসলে কীসের জন্য

ওয়েব প্যানেলে সব আছে। ফোনে বসে তুমি যা করবে তা আলাদা — সেটাই অ্যাপের কাজ:

| ফোনে দরকার | ডেস্কটপেই ভালো |
|---|---|
| কাস্টমার এলে সাথে সাথে কাজ এন্ট্রি | দীর্ঘ ইনভয়েস তৈরি ও এডিট |
| টাকা নিলে সাথে সাথে জমা এন্ট্রি | রিপোর্ট বিশ্লেষণ |
| আজ কার কত বাকি — দেখা ও রিমাইন্ডার পাঠানো | সেবা ও সাপ্লায়ার সেটআপ |
| কার ডকুমেন্টের মেয়াদ শেষ হচ্ছে | সেটিংস, টেমপ্লেট |
| সাপ্লায়ারের কাজ সম্পূর্ণ মার্ক করা | কোটেশন লেখা |
| এক ক্লিকে সেবার তালিকা পাঠানো | |

তাই অ্যাপ **সব ফিচারের কপি নয়** — উপরের ৬টা কাজের জন্য দ্রুত টুল।
বাকি কিছু দরকার হলে অ্যাপ থেকেই ওয়েব প্যানেল খুলবে।

---

## ২. স্ক্রিন তালিকা

```
Login
└── Bottom navigation (৫টা ট্যাব)
    ├── Home      : ledger strip + আজকের কাজ + বাকি + মেয়াদ
    ├── Customers : সার্চ → কাস্টমার → লেজার, ডকুমেন্ট, কাজ
    ├── Money     : বাকি তালিকা | জমা তালিকা | অ্যাডভান্স
    │              └── জমা এন্ট্রি (bottom sheet)
    ├── Work      : কাজের তালিকা → কাজ → লাইন সম্পূর্ণ, সাপ্লায়ার
    └── More      : ডকুমেন্ট, খরচ, সাপ্লায়ার, মেসেজ, সেটিংস
```

Book (Official/Personal) টগল থাকবে Home-এর উপরে, ওয়েবের মতোই।

---

## ৩. প্রযুক্তি

| স্তর | পছন্দ | কারণ |
|---|---|---|
| ভাষা | Kotlin | Android-এর ডিফল্ট |
| UI | Jetpack Compose | কম কোডে দ্রুত স্ক্রিন |
| নেটওয়ার্ক | Retrofit + OkHttp + kotlinx.serialization | টোকেন রিফ্রেশ interceptor সহজ |
| লোকাল স্টোর | DataStore (টোকেন) + Room (অফলাইন ক্যাশ) | |
| ন্যূনতম | minSdk 24, targetSdk সর্বশেষ | পুরনো ফোনও চলবে |
| আর্কিটেকচার | MVVM — Screen → ViewModel → Repository → Api | |

**অফলাইন:** পড়ার ডেটা (কাস্টমার, সেবা, বাকি তালিকা) Room-এ ক্যাশ হবে।
লেখার কাজ (জমা এন্ট্রি, কাজ এন্ট্রি) নেট ছাড়া হলে queue-তে জমা থাকবে,
নেট এলে পাঠাবে। এতে দোকানে বসে নেট না থাকলেও এন্ট্রি হারাবে না।

---

## ৪. লগইন ও টোকেন

```
POST /auth/login
{ "email": "...", "password": "...",
  "platform": "android", "device_name": "Anwar Pixel 8" }

→ { "data": { "user": {...}, "tokens": {
      "access_token": "...",   // ১ ঘণ্টা
      "refresh_token": "...",  // ৯০ দিন
      "expires_in": 3600 } } }
```

- `access_token` প্রতিটি রিকোয়েস্টে `Authorization: Bearer <token>` হেডারে
- ৪০১ পেলে **একবার** `POST /auth/refresh` করে আবার চেষ্টা করবে
- refresh token একবারই কাজ করে — প্রতিবার নতুন জোড়া আসে, পুরনোটা বাতিল
- দুটো টোকেনই **EncryptedSharedPreferences / DataStore**-এ রাখবে, প্লেইন ফাইলে নয়
- ফোন হারালে ওয়েব থেকে `DELETE /auth/devices/{id}` দিয়ে ওই ফোন লগআউট

OkHttp Authenticator দিয়ে রিফ্রেশ করলে সমান্তরাল রিকোয়েস্টে সমস্যা হয় না।

---

## ৫. প্রতিটি স্ক্রিনের API

### Home
```
GET /reports/dashboard?book=official
  → counts { customers, suppliers, services, pending_jobs,
             overdue_invoices, expiring_docs_30, queued_messages }
    receivable[] { currency, amount }   ← কে কত দেবে
    payable[]    { currency, amount }   ← তুমি কত দেবে
    advance_held[] { currency, amount }
    today / this_month / this_year { by_currency[] }

GET /payments/due-list?book=official
GET /documents/expiring?days=30
GET /jobs?pending=1&per_page=10
```

### Customers
```
GET  /customers?q=<text>&page=1&per_page=25   → data[] + meta{total,page,total_pages}
GET  /customers/{id}                          → + documents[], recent_jobs[], recent_invoices[]
GET  /customers/{id}/summary?book=official     → by_currency[], advance[], jobs{}, ledger[]
POST /customers    { name, company_name, phone, whatsapp, default_currency, ... }
PATCH /customers/{id}
```

### Money
```
GET  /payments/due-list?book=official   → rows[] + totals_by_currency{}
GET  /payments/received?from=&to=
GET  /payments?advance_only=1

POST /payments
{ "customer_id": 1, "amount": 5000, "currency": "BDT",
  "method": "bkash", "reference": "TrxID",
  "auto_allocate": true,        // পুরনো ইনভয়েস থেকে বসবে
  "notify": "whatsapp" }        // ঐচ্ছিক — কাস্টমারকে মেসেজ

// নির্দিষ্ট ইনভয়েসে বসাতে:
{ "allocations": [ { "invoice_id": 12, "amount": 5000 } ] }

POST /payments/{id}/apply-advance
POST /messages/due-reminder { "customer_id": 1, "channel": "whatsapp" }
```

⚠️ পেমেন্ট আর ইনভয়েসের কারেন্সি এক না হলে সার্ভার ৪২২ দেবে — অ্যাপে আগেই আটকাও।

### Work
```
GET   /jobs?book=official&pending=1&page=1
GET   /jobs/{id}                       → items[], suppliers[], invoices[]
POST  /jobs
{ "customer_id": 1, "title": "...", "due_date": "2026-09-15",
  "items": [ { "service_id": 2, "qty": 1, "unit_price": 7500 } ] }

PATCH /jobs/{id}/items/{item}       { "status": "completed" }
POST  /jobs/{id}/suppliers            { supplier_id, work_detail, agreed_cost, due_date }
PATCH /jobs/{id}/suppliers/{as} { "status": "completed" }

POST  /invoices { "customer_id": 1, "job_id": 5, "status": "sent" }
GET   /invoices/{id}/print-link       → { url }  ← WebView বা ব্রাউজারে খুলবে
```

### Documents
```
GET  /documents/expiring?days=30      → expired[], soon[], count{}
GET  /documents?customer_id=1
POST /documents { customer_id, title, expiry_date, remind_days, ... }
POST /documents/{id}/remind { "channel": "whatsapp", "to": "customer" }
POST /documents/{id}/renew  { "new_expiry_date": "2027-09-01" }

POST   /attachments/document/{id}  multipart/form-data, field name "file", max 10MB
GET    /attachments/document/{id}  → প্রতিটি ফাইলের view_url ও download_url (৩০ মিনিট বৈধ)
DELETE /attachments/{attachmentId}
```

ক্যামেরা দিয়ে ডকুমেন্ট স্ক্যান করে সরাসরি আপলোড — এটা অ্যাপের সবচেয়ে
কাজের ফিচার হবে, ওয়েবে যা করা কঠিন।

### Messages
```
POST /messages/service-list
{ "service_ids": [1,2], "customer_id": 3, "with_price": true }
  → { text }                        ← কপি বা শেয়ার করার জন্য
  + "send": true, "channel": "whatsapp" দিলে সরাসরি যাবে

POST /messages/custom { party_type, party_ids[], channel, body }
```

`text` ফেরত এলে Android-এর share intent দিয়ে যেকোনো অ্যাপে পাঠানো যাবে —
WhatsApp template অনুমোদন না থাকলেও এটা সবসময় কাজ করে।

---

## ৬. রেসপন্সের গঠন

সফল:
```json
{ "success": true, "message": "Payment recorded", "data": { ... } }
```
তালিকা:
```json
{ "success": true, "data": [ ... ],
  "meta": { "total": 132, "page": 1, "per_page": 25, "total_pages": 6 },
  "summary": { "by_currency": [ ... ] } }
```
ব্যর্থ:
```json
{ "success": false, "message": "Validation failed",
  "errors": { "amount": ["Amount is required"] } }
```

| কোড | মানে | অ্যাপে কী করবে |
|---|---|---|
| 401 | টোকেন শেষ | রিফ্রেশ, না হলে লগইন স্ক্রিন |
| 403 | অনুমতি নেই | বার্তা দেখাও |
| 404 | নেই | "পাওয়া যায়নি" |
| 422 | ইনপুট ভুল | `errors` ফিল্ডের নিচে দেখাও |
| 429 | বেশি চেষ্টা | কত মিনিট পরে বলো |
| 500 | সার্ভার | "আবার চেষ্টা করুন" + retry |

---

## ৭. যেসব জায়গায় ভুল হওয়ার আশঙ্কা

1. **টাকার হিসাব `Double`-এ কোরো না** — `BigDecimal` ব্যবহার করো, নাহলে
   পয়সার গরমিল হবে। API স্ট্রিং হিসেবেই সংখ্যা পাঠায়।
2. **কারেন্সি মেশানো** — এক স্ক্রিনে BDT আর AED যোগ করে দেখিও না।
   সার্ভার আলাদা সারিতে পাঠায়, অ্যাপেও আলাদা রাখো।
3. **টাইমজোন** — সার্ভার Asia/Dhaka-তে চলে। দুবাই থেকে ব্যবহার করলে
   তারিখ এক দিন এদিক-ওদিক হতে পারে; ডিভাইসের টাইমজোন নয়, সার্ভারের
   তারিখই দেখাও।
4. **অফলাইন কিউ** — একই জমা এন্ট্রি দুবার পাঠিয়ে ফেলা সবচেয়ে সহজ ভুল।
   প্রতিটি লোকাল এন্ট্রিকে UUID দাও, সার্ভারে গেলে মার্ক করো।
5. **ফাইল আপলোড** — ক্যামেরার ছবি ৫-১০ MB হয়। আপলোডের আগে
   রিসাইজ ও কমপ্রেস করো, নাহলে সীমা পার হবে।

---

## ৮. ধাপে ধাপে

| ধাপ | কী থাকবে | আনুমানিক |
|---|---|---|
| ১ | লগইন, টোকেন রিফ্রেশ, Home ড্যাশবোর্ড | ছোট |
| ২ | কাস্টমার তালিকা, ডিটেইল, লেজার | মাঝারি |
| ৩ | বাকি তালিকা, জমা এন্ট্রি, রিমাইন্ডার | মাঝারি |
| ৪ | কাজ এন্ট্রি, লাইন সম্পূর্ণ, সাপ্লায়ার | মাঝারি |
| ৫ | ডকুমেন্ট + ক্যামেরা স্ক্যান আপলোড | মাঝারি |
| ৬ | অফলাইন ক্যাশ ও কিউ, পুশ নোটিফিকেশন | বড় |

প্রতিটি ধাপ আলাদাভাবে ব্যবহারযোগ্য — ধাপ ৩ শেষ হলেই অ্যাপটা
আসল কাজে লাগতে শুরু করবে।

---

## ৯. আগে ঠিক করার বিষয়

- অ্যাপ কোন ডোমেইনে API খুঁজবে (`office.creativesit.com`?) — হার্ডকোড না
  সেটিংসে বদলানো যাবে?
- পুশ নোটিফিকেশন লাগবে কি? লাগলে Firebase সেটআপ ও সার্ভারে একটা
  `device_tokens` টেবিল যোগ করতে হবে।
- অ্যাপ শুধু তোমার জন্য, না ভবিষ্যতে স্টাফও ব্যবহার করবে?
  (`users.role` = staff আছে, কিন্তু এখনও কোনো পারমিশন লেয়ার নেই।)
