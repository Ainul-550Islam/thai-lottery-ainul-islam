# Thai Lottery — প্রাথমিক প্রকৃত অডিট

তারিখ: ২০২৬-১০-০৩  
অবস্থা: **Production-ready নয়**

## যাচাই করা হয়েছে

- Repository clone ও file inventory
- Frontend dependency clean install: `npm ci`
- Production asset build: `npm run build` — **PASS**
- NPM security audit
- Repository-তে থাকা runtime/failure evidence পর্যালোচনা

## বর্তমানে প্রমাণিত সমস্যা

1. Repository-এর সর্বশেষ নিজস্ব রিপোর্টে test suite green নয়। রিপোর্টগুলোর snapshot ভিন্ন সময়ে তৈরি এবং সেখানে 84 থেকে 248 failure পর্যন্ত উল্লেখ আছে; তাই নতুন clean PHP run ছাড়া কোনো সংখ্যাকে বর্তমান সত্য বলা যাবে না।
2. এই workspace-এ PHP ও Composer নেই। ফলে Laravel test, Pint, migration এবং runtime এখনো স্বাধীনভাবে চালিয়ে যাচাই করা যায়নি।
3. Project-এর নিজস্ব failure inventory-তে খোলা defect:
   - Public-page content/feature gap (~120)
   - Required route parameter ছাড়া URL generation (~10)
   - Lane fixture/seed mismatch ও `ModelNotFoundException` (~13)
   - Type contract errors (~29)
   - Payment-method test configuration/wiring gap (~8)
   - নিরাপত্তাসংবেদনশীল mass-assignment সমস্যা (`User.kyc_status`, `GloL6Sale.created_at`)
4. `npm audit` ফল: **5 high-severity** development dependency vulnerability। মূল chain Tailwind CSS 3 → chokidar/micromatch/braces। Suggested fix Tailwind 4 major upgrade; blind `--force` করা হয়নি, কারণ এতে UI/build ভাঙতে পারে।
5. Frontend production build সফল হয়েছে এবং Vite manifest তৈরি হয়। `public/build` gitignore হওয়ায় generated artifact source commit-এ যোগ হয়নি।
6. Runtime verification নেই: production DB, queue, cache, browser flow, payment webhook, live result importer—কোনোটিই deployed environment-এ প্রমাণিত নয়।

## Real-money launch-এর বাধ্যতামূলক gate

- প্রযোজ্য jurisdiction-এর lottery/gambling licence ও legal approval
- KYC/AML এবং age/geolocation controls
- অনুমোদিত payment provider ও signed webhook verification
- Immutable double-entry ledger, idempotency এবং reconciliation
- Responsible-gaming controls ও self-exclusion
- Production secrets, backups, monitoring, incident response
- MariaDB/Redis/queue concurrency test
- Independent security review ও penetration test
- সব automated test green এবং deployment smoke/E2E green

## কাজের নিরাপদ ক্রম

1. PHP 8.4 + Composer environment provision
2. Clean dependency install এবং সম্পূর্ণ test/Pint baseline পুনরায় সংগ্রহ
3. প্রথমে fatal/type/routing/migration error ঠিক করা
4. Money-safety: wallet, ledger, webhook, payout, KYC/AML
5. Seed/fixture এবং payment configuration ঠিক করা
6. Public-page acceptance failures এক একটি suite ধরে ঠিক করা
7. Tailwind 4 migration আলাদা branch/step-এ করে visual regression test
8. MariaDB + Redis + queue integration run
9. Browser E2E ও accessibility test
10. Staging deployment, security review, তারপরই production decision

## সততা নীতি

- শুধু test pass করাতে security validation দুর্বল করা হবে না।
- `kyc_status`-এর মতো protected field `$fillable` করে দেওয়া হবে না।
- missing business rule অনুমান করে real-money behaviour বানানো হবে না।
- আইনগত অনুমোদন ও provider credentials ছাড়া system-কে “real business ready” বলা হবে না।
