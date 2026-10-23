# Release source audit — static inventory, not certification

Scope: STATIC_ONLY. Runtime, production, live payments and official-source authenticity are NOT_VERIFIED.
These page IDs preserve requested inventory slots, not a claim that all pages are implemented or dispatched.
Pages 77–250 have no authoritative mapping established here: NOT_CONFIGURED. Pages 251–350 retain only titles statically extracted from the old recipe.
The old recipe is not executed; its obsolete availability and financial/runtime success assertions are not adopted.

## Source byte inventory

| Source | Exists | SHA-256 |
|---|---|---|
| `tests/Feature/Finance/WalletWriteRefusalTest.php` | True | 8511e7eb26c890431d9c0d4aca8b5be08b1e4abeeae8fd3279cb5ad2fec9c17d |
| `tests/Feature/Finance/LedgerPostingWriteRefusalTest.php` | True | e28d13b6431011fc785fd82a25f50886cd7f5641bf76575b8e1896532a1a3199 |
| `tests/Feature/Finance/LedgerPostingCallbackJournalTest.php` | True | 419b4a523ec35aa73eb3ab287b5166e3dbd934c7689c02250044d9709010ddee |
| `app/Services/Finance/LedgerPostingInputWitness.php` | True | 163e387f1236ad6bac6f1ecad2fd91dbd9863f2a2fec19ff9335bd7ba0d679d3 |
| `tests/Unit/Services/LedgerPostingInputWitnessTest.php` | True | d64ce94bc5eb5a46ae6d788f03f3d50690f4764e4f8b61589b60a127c314358b |
| `tests/Feature/Finance/LedgerPostingInputDriftTest.php` | True | 74926ea8adf0d85b72ba2ebb6a4a6f70d96deb503f3fa0fd23fb06256f917352 |
| `app/Services/Finance/LedgerPostingInvocationLease.php` | True | c141b41bde4b27c4ca72bcc32885cf9e9966e5d0d34a8fda2181f09e20d0fe6d |
| `tests/Unit/Services/LedgerPostingInvocationLeaseTest.php` | True | 3a82eeb368e827cf3ad841e33c30ceeb26e521309b4c47bdc249e491b215e08d |
| `tests/Feature/Finance/LedgerPostingReentrancyTest.php` | True | bd57b541f02f93aa29c38d7452161d96ee84329301ed945505d594f6e90cebd3 |
| `app/Services/Finance/LedgerPostingWriteBoundary.php` | True | d414675f909a9ae9c25ef52bb89f882220a779c3194a3c5260264fb72a8df649 |
| `tests/Unit/Services/LedgerPostingWriteBoundaryTest.php` | True | 66b8d620c2eb478d200413ecd822150d3365577a288425f6ef89a0ccedc67087 |
| `tests/Feature/Finance/LedgerPostingWriteBoundaryTest.php` | True | 46c6077bf3c28a5253035784d835aa6311ef43912d1bfa099f793055365c63f8 |
| `app/Services/Finance/LedgerEntryPayloadPreflight.php` | True | 806533f02f66de343f282a84593cc5b7a782d7e1a8716b8d83f04e934ae86075 |
| `tests/Unit/Services/LedgerEntryPayloadPreflightTest.php` | True | 19951b4136756b41a0e228eac653b47bddf9bc60962aa68cc569f69f6b1ab2bb |
| `tests/Feature/Finance/LedgerPostingEntryPayloadTest.php` | True | af3a2f8ce6f6f96d1fe55022014450a714aa8e3c665a7b3b540f8d521e23e092 |
| `app/Services/Finance/LedgerEntryMoneyPreflight.php` | True | 761e358cf665215525d54fb9695b139fb13f1be85a34643c83126d9aebf26df5 |
| `tests/Unit/Services/LedgerEntryMoneyPreflightTest.php` | True | af4d6e1a1c1082950e34a50d2bf36d89c0b6168ea1fbd0a2468f87e96e1ba2d1 |
| `tests/Feature/Finance/LedgerPostingEntryMoneyTest.php` | True | d20d6a9c13c236300c4d0d6ebde0ac6958a32ee49e1f4adbed7478fe4386d4a9 |
| `app/Services/Finance/LedgerAccountBalanceProjector.php` | True | 54641f122ce05d403e7ab07692daa3425e339aa5fa1f204dd08cddd82b120509 |
| `tests/Unit/Services/LedgerAccountBalanceProjectorTest.php` | True | 2d49202df55b6374bee7acb06f31e26a9943b14a5d1011620e657357637b977a |
| `tests/Feature/Finance/LedgerPostingBalanceProjectionTest.php` | True | 7a27add8e7829024ec6d43ec6bf8693ae00cfb9cc17262c61729cdae7e22ea85 |
| `tests/Feature/Finance/LedgerPostingDraftIdentityTest.php` | True | c875de1b9fa456df6627ad0cbf08c072a9e866f3c499c92903258bb26485c6d1 |
| `app/Services/Finance/LedgerPostingObserver.php` | True | 7135e61ce59aafe5117d341fa7611ddfa164236abfe85232e65529cc55320d7e |
| `tests/Feature/Finance/LedgerPostingObserverOwnershipTest.php` | True | 153d6a7c6c283d2229945f61685014649dc1c3bdffb8c9fb8ab7e600420c579c |
| `tests/Feature/Finance/LedgerPostingCurrentReadTest.php` | True | e8eda867c86b7eeee9995cd42d15d4356467f105d33ae94ff4eb0a875aef6e27 |
| `tests/Feature/Finance/LedgerPostingCurrentReadServerTest.php` | True | a9932eafdd28d899144af06ae5939a403494b0487dec7573676970da6869c239 |
| `tests/Support/LedgerPostingProbe.php` | True | dcb283626bd5f4c9fcaf6a894aa95cfe983e56736084941f3c3fb2255cac5052 |
| `tests/Feature/Finance/LedgerPostingAdmissionTest.php` | True | 1f4fce0b3920a750c1d1b3684a2251c457163dd1ddcd389e8cd926aa49baaea7 |
| `tests/Feature/Finance/FinancialModelRoutingContextTest.php` | True | 751bfd981a62c0db7982f422ec51063288d486d5d15b48b9f0bd4a0f71c2428c |
| `app/Services/Finance/FinancialRecordIdentifier.php` | True | e754137067f6726dc907aaad72fda073b794922469354ee2ff700b8f41ff1112 |
| `tests/Unit/FinancialRecordIdentifierTest.php` | True | 1b5a473435b890975ba5d93d962bfc0930d397552e481701a827d2d02465cdbc |
| `tests/Feature/Finance/FinancialIdentifierRoutingTest.php` | True | 4a3b9ebe8d60f803cbbdc7d3a2cfacab084ef666f286da2b23e6993b790b533f |
| `tests/Feature/Finance/FinancialRetryOwnershipTest.php` | True | e4e810c253ea695c8888e12f1caf9ea84e148309a06d4b8c7c2460a5d40f9fd9 |
| `tests/Feature/Finance/FinancialRetryOwnershipServerTest.php` | True | cb79fbf94bb45166d9ff8c8168a0f927829adfa08f86d65985eabae0375ff845 |
| `tests/Support/FinancialRetryProbe.php` | True | bbd95a93d6b172a52fd27dc13589902425b45cf42909d82046b5954355d07ac0 |
| `scripts/verify_financial_retry_ci.py` | True | 1f463b91c15afe058c73798b9c173e754daa521f2f6ebd002087b6e987ac1f85 |
| `scripts/test_verify_financial_retry_ci.py` | True | d93e982d4e73f9e26499e5bf21a75afb907c9cf008d6b8447f25a24eafccaf89 |
| `app/Services/Finance/FinancialStateTransitionService.php` | True | 87130c00fb17808746a916e76d0dce158c8fa7ef1b632d8ab1bb961ef4ae01fa |
| `tests/Support/LifecycleBoundaryOperation.php` | True | ffec18717364138cbe8228ac5b1611ee427ff8b7633e03c4ac21ef9a8b38f20e |
| `tests/Feature/Finance/LifecycleTransactionBoundaryTest.php` | True | 53b73ecd9b1bba2d634b0215fb71be94d2edc3d4dafe1d875503cdd36d2b7983 |
| `tests/Feature/Finance/LifecycleTransactionBoundaryServerTest.php` | True | d26e65641f6241612995723e8c39afb83b1da9ac8c2e5d716c341c0f083e134c |
| `scripts/verify_lifecycle_boundary_ci.py` | True | 05ea05baa480638bdfe33e80027631e8ac0df4d4f9a6f6b09a27713bc38d5ecb |
| `scripts/test_verify_lifecycle_boundary_ci.py` | True | fc856fc8e33b0aeea086c1763faf3ff09a7c62a366a49f0ecb917368f18dae6f |
| `tests/Support/ReversalBoundaryOperation.php` | True | 34fadb7ce44fbb265550715935d64defd1072f615d0772f7734dd0f6657949e3 |
| `tests/Feature/Finance/ReversalTransactionBoundaryTest.php` | True | 45e0b0765c86b26e53c4d7917c5bf6ecff9ec67797bc2e56a5ed549098b7af3f |
| `tests/Feature/Finance/ReversalTransactionBoundaryServerTest.php` | True | 6e6650c342fb7fa926460812088ed1c3508baf673ebd236981973e8aa21315db |
| `app/Services/Finance/WalletLockService.php` | True | d6c675e2f80323e1d798e546e67d1189ac2d15df007e028c41886aad0e31c444 |
| `app/Services/Finance/WalletHoldService.php` | True | df61b66ee3b95042c2859aa389eeeb37276f40f01425959855fe05fd593f2696 |
| `app/Services/Finance/FinancialTransactionService.php` | True | f11693c3c219c6c7c2b5eeca96e544a84cd464c5c15a29594ef5da96799b2d39 |
| `tests/Support/WalletBoundaryOperation.php` | True | 337846de2112c5d7d72aa41b8b756eeb423b9d2724e9287df7023e2093f17623 |
| `tests/Feature/Finance/WalletTransactionBoundaryTest.php` | True | 91419ca86fc51ca663446a8109ad3ed7342e99a2f05cda7895b2579b1a04520d |
| `tests/Feature/Finance/WalletTransactionBoundaryServerTest.php` | True | e77d4b17bc2d4971420c2b67a1e009026cfbf93acda2e450e0ca24d4d899b42a |
| `app/Services/Wallet/Conversion/ConversionWalletEligibility.php` | True | 686ec74c030999d23b167496cf1058fbaee0a35cdf54612ec2e17b33f5136e40 |
| `tests/Support/ConversionWalletEligibilityFixture.php` | True | aa7b64a7fd4cdd29f9b67321be9d3952e707ef3a941feb645b409ccb1dd508c2 |
| `tests/Feature/Wallet/Conversion/ConversionWalletEligibilityTest.php` | True | 18f3d551c49605ab2d595c75045a93adb8f8e31f1c542182b803e12b4c70a594 |
| `tests/Feature/Wallet/Conversion/ConversionWalletEligibilityServerTest.php` | True | 41abe73a349d939f51d2114b7f00b8d03994a23491829cf0fd6a1a225c3f671f |
| `app/Services/Wallet/Conversion/ConversionMemberEligibility.php` | True | 8db203fb4b9ec918e6e76897fc16ef638062684b05b9b4fa02d17337f97a70a9 |
| `app/Services/Wallet/Conversion/ConversionPolicyGuard.php` | True | 650b40b8106edafc5a251b8bed105ac401f98077f78fe336beb96aaf23e29c04 |
| `tests/Support/ConversionMemberEligibilityFixture.php` | True | 55bfce6ed82e1b7663180a2797d06f533796b9a8a302f13fe0dc03c0a9ba876d |
| `tests/Feature/Wallet/Conversion/ConversionMemberEligibilityTest.php` | True | cfc9ff448053ccfbabcca9a7c1873f41eed0ad720f49d65a3cef7725c6e31a44 |
| `tests/Feature/Wallet/Conversion/ConversionMemberEligibilityServerTest.php` | True | 08b863e2b4602544db20f2b623e310818b5d35d9951dd981cd0bbc065f6d2f60 |
| `app/Repositories/Wallet/WalletConversionQuoteRepository.php` | True | 5fcaabcca36b9064d63baa516d3e162b46a40f694462acc7166328101457aa8a |
| `app/Services/Wallet/Conversion/ConversionQuoteService.php` | True | 581213ba828668d79ded970fd1857357c18720b30608df108de6fca0b555df3f |
| `tests/Support/ConversionQuoteSlotFixture.php` | True | de063dc9986a607d8d42e40093ee83b540df3462f97d966e4b6531aa96787f88 |
| `tests/Feature/Wallet/Conversion/ConversionQuoteSlotTest.php` | True | 50b6c342987b814ed8cd5e6388fda31f60f7fbc7dfcb32fe7441e9237f08cb4c |
| `tests/Feature/Wallet/Conversion/ConversionQuoteSlotServerTest.php` | True | 5d35c7acbe618184249855cb9d09144c398d0e19712769d6da5a271870bacc00 |
| `app/Services/Wallet/Conversion/ConversionLimitService.php` | True | 8e5152028ef7d1360be95b702cac1e7a9cdb33e8e94384640c729ee5cc9ad8da |
| `tests/Support/ConversionRollingLimitFixture.php` | True | 26c7bd314eb0eaf772d87cb23e28a3ebefbd0cf0d8ea4ff2c52beb0d0c24bccc |
| `tests/Feature/Wallet/Conversion/ConversionRollingLimitTest.php` | True | 6492ba6245d01dd512f0a575d559773a2f8a036040d4f36609c4e90f79169f7d |
| `tests/Feature/Wallet/Conversion/ConversionRollingLimitServerTest.php` | True | 5d8b749692f53767f5a9e5290a1641923030c2afcca86beca11c1347ee5c0e82 |
| `app/Services/Wallet/Conversion/ConversionReversalAuthority.php` | True | fb0219e5a10673b84082f2e9ffa119e6acda5501c1e726ada0ec0448cce24f58 |
| `tests/Support/ConversionReversalAuthorityFixture.php` | True | 5c03907db8398b270d9caa4d6c59f1859a2ce81cfd92e411e7d698f8ff7a5172 |
| `tests/Feature/Wallet/Conversion/ConversionReversalAuthorityTest.php` | True | 1cae3bb32c781a6e12e6712603b6d2012a39db09b9db271648f7c9d700545182 |
| `tests/Feature/Wallet/Conversion/ConversionReversalAuthorityServerTest.php` | True | 37e624bede852fb79a7040ee3c0dd090b3ecaa14b6e26fbbc8e911ad834d3081 |
| `app/Services/Wallet/Conversion/ConversionIntegrityGuard.php` | True | 7d3e4d936fc3bfa8e16731e76b0ee5d12850f27fad23fa649b9c3aeec32d951f |
| `app/Services/Finance/LedgerPostingService.php` | True | 77317a22641309d7e254476339a593b72e4fe0de91db72c7eba1526d3476bd17 |
| `tests/Feature/Wallet/Conversion/ConversionOriginalProofTest.php` | True | 67caf5b69c00d22ce5aeeab23c136dd0702b2fc4c68356335d3d24e1dd3cf221 |
| `tests/Feature/Wallet/Conversion/ConversionOriginalProofServerTest.php` | True | ee1c960d6a56cf92b3a0a9b92ca1d834c5bc49deeeff08f9f80cc3914a7b928e |
| `tests/Support/RunsServerFinancialRaces.php` | True | 08d4a205d6bf361572b951c28cda28d23482bce308c495ecb72c5c6b969941de |
| `app/Services/Wallet/Conversion/StripeRefundConversionGuard.php` | True | 0fbacc61b1969a2911d1df881afc6b174e8594112091d3f9153723e9cc22dcbd |
| `app/Services/Wallet/Conversion/WalletConversionService.php` | True | a038e6b2e669dcf6ec6e9bdb116b1815cdffaaf7c1ea4e454be62fef366d5a5b |
| `tests/Support/StripeRefundConversionFixture.php` | True | d6ce51debabe55412626494dffd9eac7ea820c64f1e0e31303e4953981b2c0e8 |
| `tests/Feature/Wallet/Conversion/StripeRefundConversionGuardTest.php` | True | 5e239dc252d7162e7e01611bcb16ef2a46b61290c0bf22a5df1c08c5f965cd95 |
| `tests/Feature/Wallet/Conversion/StripeRefundConversionGuardServerTest.php` | True | f6e21d02c9fc66d373c15633cef4e9e2f9b62c2c07a1f71df8c682697d39d03e |
| `app/Services/Finance/WithdrawalInitialHoldService.php` | True | 81f61a128f30adecacbf04877daa55c940dc47480bb66372392fb26140c4c070 |
| `database/migrations/2026_10_18_000700_create_withdrawal_initial_holds.php` | True | 98a7140f397a324a04881b6d93e863a230386467c8db2c6953928878f1476cd3 |
| `app/Services/Finance/WithdrawalRequestWithHoldService.php` | True | c5248795b90a81bae3d1e273a6a1af74f94af0dee03bcd6d145beb6baccc477d |
| `app/Services/Finance/WithdrawalReservationService.php` | True | dd510315da2e10c32b5085b993d34dd52375e2b98f0c1b2d0ef763b036059e92 |
| `app/Services/Finance/WithdrawalHoldFloor.php` | True | e6cf8e7bb681a6ad1e01078bef77c6722256a21258cdbc59090350cd9176b1bb |
| `app/Services/Finance/WithdrawalService.php` | True | 8045bccda97f116399c0d4e6964571404164c007b053939be658bc0c82b1f349 |
| `app/Services/Finance/WithdrawalApprovalService.php` | True | 700867d2aeb51f5b6957af15c73a8f9a5e68259e33daf7254ae3f7e59af90802 |
| `app/Services/Finance/WithdrawalIntegrityGuard.php` | True | fc5ccebb03284d38ca0a6946bbdfe977566427787b8f1d9b4a0772a290158dd2 |
| `app/Enums/WithdrawalStatus.php` | True | 993b3af8c4b082574fe79f90a2224796ede99c28a201331da817f319b4f64c06 |
| `tests/Feature/Payment/WithdrawalInitialHoldHandoffTest.php` | True | 50225a388eca13a2ea834c0a3a5d984e4e0700d903b0d812367f0184131e7d8d |
| `tests/Feature/Payment/WithdrawalInitialHoldHandoffServerTest.php` | True | ea311f5b2f0af01bab6e9ed9fc88826e8429d53ede4a6a0b176f27eeddff311c |
| `tests/Support/FinancialRaceProbe.php` | True | f4ed490ad4a5e9945975bfd4e9291efd3c1de6eacba7cbf333655fa5827e0b44 |
| `.github/workflows/financial-concurrency.yml` | True | d03083f9cca9adc2785203ab72832b8e3c4a43a68b95b6770a8375dd0afe72ea |
| `scripts/release_source_inventory.py` | True | 808a59016be525ba652d2ac2ead28e12cd6ed8dab54d6937fbeae247cf43baaa |
| `app/Services/Finance/StripeRefundWithdrawalGuard.php` | True | 66002232c3d370df914d3f46dba50ef118fde96ccfd40b6d4bc7e1dba86fb484 |
| `app/Http/Controllers/Api/V1/WithdrawalController.php` | True | 07e10b34f480f859c974b7a89cd5a35bb89a4242bf569e225ca2cf08156a6d87 |
| `tests/Feature/Payment/StripeRefundWithdrawalGuardTest.php` | True | 8e28318f836487e8b6e8173a81c2a102fcc7222508388855b942bceacf434dc0 |
| `tests/Feature/Payment/StripeRefundWithdrawalGuardServerTest.php` | True | eec0d1ed6f83e1acf8579a4be022e928857e161ed1fbedef9f8dd39176f38cae |
| `app/Services/Betting/BetPurchaseTransactionService.php` | True | 019d1ab698a557f868e002489fc77ab71de3a351a3cf20e6ae00cc6c4bbbccc0 |
| `app/Services/Betting/StripeRefundWagerGuard.php` | True | 80eaeabed73b5a6f4d23726408c78fbb5ec94945eae7b1dc63292382985c1b3b |
| `app/Services/Betting/BetPurchaseAdmission.php` | True | e59f46d8a5af2748f3159682f275b51f68c3881ebe4bbf77f93a7b8061f0dbb8 |
| `app/Http/Support/BetPurchaseErrorMapper.php` | True | 1675a8053cf6aba955a4725f56af4f89cb704e622506597ccb88e9c9858190eb |
| `app/Services/Betting/BetAmendmentService.php` | True | 3ac511ac754efda1356bfca5057332bc9eae9f70e38f90c7f7d5320f6469574e |
| `tests/Support/StripeRefundWagerFixture.php` | True | 479e9257d1bc6eb482a64c50ae96b859075c4d862cfc9f00aa4527fe2f363eb7 |
| `tests/Feature/Betting/StripeRefundWagerGuardTest.php` | True | dd8636dbf34221fd26bbaea38b7e19897c68c441ab74f7b83e06da50ee5b1ebb |
| `tests/Feature/Betting/StripeRefundWagerGuardServerTest.php` | True | 0a6ddaa74ae3a6165f80a9645447c85635c010c5f01d7d4254d6dd321e6e3763 |
| `database/migrations/2026_10_18_000600_create_stripe_refund_recoveries.php` | True | 943f687b107a7126b62b2dad5ff659795a110d24231c6be330aed3662dd4d835 |
| `app/Services/Payment/StripeRefundRecoveryAccess.php` | True | 138d1544a20fef24e893347f11ee7cf14445147a2f722c35fbee45728dfb8f37 |
| `app/Services/Payment/StripeRefundRecoveryLookup.php` | True | 7548249773552f55d39c6e2a3656990a43211dba549dcbbcb5117ea882e69ebc |
| `app/Services/Payment/StripeRefundRecoveryService.php` | True | 77a2eb9bd72a92c479500bff3cffdbefd1a404c5ba48c186c5325e2974113f9e |
| `app/Models/StripeRefundRecoveryIntent.php` | True | f7f58c17e98b9d0991f35a906b35168ffb91b17967b002f3d42febb27b2127eb |
| `tests/Support/StripeRefundRecoveryFixture.php` | True | 641395d1154db034330a43ad7ee32b98ff73ab8afb9898e81b53cba36cdf0d4b |
| `tests/Feature/Payment/StripeRefundRecoveryTest.php` | True | 7b4858e40b7a117e1af4bd85fe7bc01812736da2065ba68c49c67c03a7329bb0 |
| `tests/Feature/Payment/StripeRefundRecoveryServerTest.php` | True | 9d6ef87d5fb2ecd426b6f645355f0102ce175f85af8e5b2018d4be80caac028f |
| `database/migrations/2026_10_18_000500_create_stripe_followup_sweep_work.php` | True | 9bf86610fe58800be15588afb878ef8e44692a0605d15e6d5a6e0bda429f87ba |
| `app/Services/Payment/StripeFollowupSweepService.php` | True | 15ce5bcb33a85e92db35dfae58f88813b2659a846a672f1deb52e0faadf30037 |
| `app/Console/Commands/StripeFollowupSweep.php` | True | 8a1b84ad8a948fe494568fefccda78b6b41075fca9cca18347775499a8b5c70c |
| `app/Models/StripeFollowupSweepWork.php` | True | 566d8b3625aceb79da16abb099d1645648b3612f7adf2d3308805bc80e9226f4 |
| `routes/console.php` | True | 51b847569a0d813c1598e542729f21f29f2cd70cb681e4ca1f4cb7f01eee5a95 |
| `tests/Feature/Payment/StripeFollowupSweepTest.php` | True | d3a53479bfd693a1a69bd0166c8e170faa4c08adb11f3c298fbf2b0b8d7e27bc |
| `tests/Feature/Payment/StripeFollowupSweepServerTest.php` | True | cb0b3e8b10cccb0d255b1bd8c7496de19b06b788e063002bec39529b26229e63 |
| `database/migrations/2026_10_18_000400_create_stripe_followup_route_decisions.php` | True | 51bf37bf365fb578661eebb1bb81aa58e77cce986bacafd6017d4a0ba1f6f04f |
| `app/Services/Payment/StripeFollowupRoutingService.php` | True | 4ce0ca4487144ddc09d0b31cb729f856494068e44b28d9aff1b507a73019332e |
| `app/Models/StripeDepositFollowup.php` | True | d39b48e1cff532e25151de5fd3b1334f0bb42e0e1a1632a0dcd6f4a93598a377 |
| `app/Models/StripeFollowupRouteDecision.php` | True | bc3e359371ee9f997900f203f8926b1a1a0c8e55f22bf8de9f7e7f92a2c47df1 |
| `app/Filament/Resources/StripeDepositFollowupResource.php` | True | 1867d4ca1e6005c8ad578665e2f79c69ea16dafeb425b567c57b2d160b31e214 |
| `app/Filament/Resources/StripeDepositFollowupResource/Pages/ListStripeDepositFollowups.php` | True | 6f259fc55c942e8a23d0629109495a9699e8ede1d7cd2d7d5c0a7e2190e76602 |
| `tests/Feature/Payment/StripeFollowupRoutingTest.php` | True | 8805f4892617b048c3759f61cff74d77b879ada4858a8c14b8261a51d5be911a |
| `tests/Feature/Payment/StripeFollowupRoutingServerTest.php` | True | 3e766a4a831c6d2ab82ff2b0a1ada57d40eeda7f6e62eaa46e2764ba65cbe44b |
| `database/factories/Support/LotteryFactoryDate.php` | True | 186f6f90fc3fc3ce43373679f42b5e714725016b55e2e8739b20e4d2466b8eb5 |
| `database/factories/BingoLotteryDrawFactory.php` | True | 43f17789ec49219514627e26a343a4a49bbf363cc383a1437374d2a607b67e20 |
| `database/factories/WeeklyLotteryDrawFactory.php` | True | f52ccff7262b26f419950cb84d4b659c9c6aeab7b6503e9c53dd8105c1e41fc6 |
| `database/factories/NationalLotteryDrawFactory.php` | True | b02fdd619781c85b7a04445db40276ede7951043ab99d6155184be4e14fd8b74 |
| `tests/Feature/Database/LotteryFactoryDateTest.php` | True | 08710e62ca130aeb394fb3889a3bb4603795764f3e5bdd4e300d19593b4d2cc0 |
| `database/migrations/2026_10_18_000300_create_stripe_deposit_review_cases.php` | True | ba3ec08c670313aebf62f8116a27f62518fc9e00346611050e33b0194af7d3ff |
| `app/Services/Payment/DepositReviewAccess.php` | True | 33de7a3736e57d24c6f24d2e027ab85c4b6722d9cf1512f703fe164de20e009e |
| `app/Services/Payment/StripeDepositFollowupInbox.php` | True | f44ed596c5c8341f3f22d0eacb78a783ceef10d8d042d2aa33225b332442af97 |
| `app/Services/Payment/StripeDepositReviewService.php` | True | 092cb210da4bc4d25a4463303fbb85e911a3c5a67a522936027406fe48adec56 |
| `app/Http/Controllers/Api/V1/StripeDepositFollowupController.php` | True | a83ac35bf003f349584cf1f7831b336d8ac992aa3098085c2bb26522b197d7bc |
| `app/Filament/Resources/DepositResource/StripeDepositReviewActions.php` | True | 179d796c10e39b026ffcb6bf770abd2b286a5419a943383dbf7cdda10f41227e |
| `tests/Support/StripeDepositReviewFixture.php` | True | 89d118d33da1d8570737455f249015fa752410cae135f49ffff961b137ab2c80 |
| `tests/Feature/Payment/StripeDepositReviewTest.php` | True | 8df843be2e7f21eabc43755ee7ed2c972fa0099535eaa7c28b7990fe46821dfe |
| `tests/Feature/Payment/StripeDepositReviewServerTest.php` | True | 55f3e08ca57c2ce813a694b214163d5553ffe582c2e23e81df3b272d486b0f97 |
| `app/Services/Payment/DepositSettlementAccess.php` | True | 06888c9336200dcb9b30e7f651f863db980b7336d528b056faf6233f3146f7b6 |
| `app/Services/Payment/StripeDepositCaptureLookup.php` | True | 21b8944b5365d48625dcc5edc08824a8a45aa0e7fd92c0a30f4ab51eca7767f4 |
| `app/Services/Payment/StripeDepositSettlementService.php` | True | 54c922ba4ed248c91c8bf86c5d7b26f23afe56254fe156ffc9c179fd993b4377 |
| `database/migrations/2026_10_18_000200_create_stripe_deposit_settlement_receipts.php` | True | c503d9a5c5b6c6867d926d3ec26a3e798b1a8a01aec7ec27247a23400a4b9ed0 |
| `tests/Feature/Payment/StripeDepositSettlementTest.php` | True | 35f10616b6088b77a4c1c806ce9e5ec54eed9ac9a549bd90ba5025a3f6397f2a |
| `tests/Feature/Payment/StripeDepositSettlementServerTest.php` | True | 62b5c93511c3fb489f337dff7c0a1b93f0b31f668c581c2ca8305903747eadfc |
| `app/Services/Payment/DepositCheckoutAccess.php` | True | f035f5db9d755ed06302b071caa6d51abb81728cda9ccb18a94b86130ffc0911 |
| `app/Services/Payment/StripeCheckoutLookup.php` | True | fdbf1db859949949164cd8912000527a48e0658a4ea81fe09f320c5ad1cdffc7 |
| `database/migrations/2026_10_18_000100_create_deposit_checkout_lookup_receipts_table.php` | True | 1887a79506b5056434496d06c8631c5b158f5bf5b82921f5eab9304a3706610f |
| `tests/Feature/Payment/StripeCheckoutReconciliationTest.php` | True | 0dbc700f530e83139ed495602fadaea24ff4b58c425b712c4326fccde00d378e |
| `tests/Feature/Payment/StripeCheckoutReconciliationServerTest.php` | True | c93f7d9e8a0808ac47c80d9708e9d2e5fa0ae316331e2e5abb1661cfe1747b33 |
| `app/Services/Payment/DepositCheckoutService.php` | True | abd5976154a549e03213e34d8790dc4ed469c4950297f589125a2d9fd7e43f17 |
| `database/migrations/2026_10_17_000500_create_deposit_checkout_attempts_table.php` | True | 44014f3376185b0aa56abe40971626485a234f322b99f89d73a23b0013f0872a |
| `tests/Feature/Payment/DurableDepositCheckoutTest.php` | True | ff2d28f932967e1c5fa27ad9c25725a577109ecb3ca15f2b3b18044f45d09830 |
| `tests/Feature/Payment/DurableDepositCheckoutServerTest.php` | True | 841853f9f939dba18192b7ee9769b08b4754fbed9905a3b0afa81dfd33cb4bb5 |
| `tests/Support/SyntheticDepositCheckoutGateway.php` | True | df90044c877ae04aa730a9f1bb43e6449f6aeb8ded93d8012eb7735400516d91 |
| `app/Services/Finance/DepositAdmission.php` | True | e0b0417874c79a273720ea035e69c7f75c35ec5328bac37a6b05fe7a6ac07f15 |
| `app/Services/Payment/PaymentInitiationService.php` | True | 246a6efe7fe97b000b37f0bf2c5c6b5077f0524f6a4a0f977519b65dca5cd745 |
| `tests/Feature/Compliance/PlayerDepositAdmissionTest.php` | True | a830d54c40e5ab4f39315a4188e91170af76ae0c99c69402bd0b32e6aebca520 |
| `tests/Feature/Compliance/PlayerDepositAdmissionServerTest.php` | True | deb60aa7c0401ee6fc616b6b5733159e7dbad206990733ab9e88e963dd7b63a6 |
| `app/DTOs/ResponsibleGaming/ResponsibleGamingLimitData.php` | True | 794d59e85648dc1e03337d57371e269c5c7984071ab35846bb70f1b24839ac0e |
| `database/migrations/2026_10_17_000400_create_responsible_gaming_limit_ownerships_table.php` | True | d1e67e225a72146caa768a43aacf7c4e62cd9b7dba8b2ef8117de1a17c62ba97 |
| `app/Services/ResponsibleGaming/ResponsibleGamingLimitOwnership.php` | True | cd86beead8a4d8a9e966790ebdc34cae01a77ecc619f4748dda27fa9ddd06e8b |
| `app/Services/Security/ResponsibleGamingService.php` | True | 59de5ee88bbca329028c7f2626daa9cd0007f0e98f3bcb467441583b4347c29d |
| `app/Http/Controllers/Api/V1/ResponsibleGamingController.php` | True | 55db7a884edc5a36efa49a0251b8be66d3cacff6ea1ff6f1c601e9e183f96c81 |
| `bootstrap/app.php` | True | 594d1cc909e84cc171be4b448b25836e1edc0cd3eb99425ea4944a60b8d136b1 |
| `tests/Feature/Compliance/ResponsibleGamingLimitLifecycleTest.php` | True | e90d7ab897164bf2a1a2c9e580d8b94bb94955361bbca891701b059108e4735d |
| `tests/Feature/Compliance/ResponsibleGamingLimitLifecycleServerTest.php` | True | c2f4aed3f9b36725bb0d4f492b40c0377c715e066c4433f3406ae5c707554695 |
| `app/Services/ResponsibleGaming/ResponsibleGamingPlayerLock.php` | True | 19ae05256cc31333b3858cf19d8c55ebc6e9c3315d64ffce2ddeff569fd6a065 |
| `app/Services/ResponsibleGaming/ResponsibleGamingEnforcementService.php` | True | 572fbc8e6adecf367437f64a8764d889e1c5b0bcbe40cbc4bfc35a9cee8baa34 |
| `app/Services/ResponsibleGaming/ResponsibleGamingLimitService.php` | True | 73274c09d70f730c70ce1aa47e1d151a54bf67ad8e703f8a349b66de1b2e691f |
| `app/Services/ResponsibleGaming/SelfExclusionService.php` | True | 0afe21b943170ee10b619407a4af94ab263d3f2921f6f8cfe50a32ba1ba25446 |
| `tests/Feature/Betting/PlayerWagerAdmissionTest.php` | True | 09b34926b52cb3cf21151c766fc0189a8e8ecaaaa2453a96c7e90c992f86b8d2 |
| `tests/Feature/Betting/PlayerWagerAdmissionServerTest.php` | True | 42446570a9cadef6cc1593f56fc194b4980ad5a2c5c29c75a33ee71bf60b5e60 |
| `app/Services/Risk/DrawRiskAdmission.php` | True | ec8ddbcb892b41114d3e0c664c25cad48155595aa75a148bf221217137c4983d |
| `app/Services/Risk/RiskAssessmentService.php` | True | ed9dcb1c6ac41798f97cce5ae071f77d0491bb4c1a34961bea9142c4fb7e8253 |
| `tests/Feature/Risk/DrawRiskAdmissionTest.php` | True | d5361194aa84bde3ff5867ee4a202293f78b63b2b5162f42e438c2a990de255c |
| `tests/Feature/Risk/DrawRiskAdmissionServerTest.php` | True | 19a1c99ebac19eb5f85d1014138c3e0320fb09aee3e36bd0050628614846a6d7 |
| `tests/Feature/Filament/OwnedDrawCancellationActionTest.php` | True | 115452164343277e5cca100f70decb2461e0bbbda66d2e3bc60af13a0215a9ab |
| `database/migrations/2026_10_17_000300_create_draw_cancellation_ownerships_table.php` | True | 49942e6a3e2578390133ecc2bf35e8dcc1cd4105acc6c8510711ad1082c39b80 |
| `app/Services/Draw/DrawCancellationService.php` | True | 1be5ca5b318a8029c4658d60c9969b14633287c30d6d6ad661f905e344f4db15 |
| `app/Services/Draw/DrawLifecycleService.php` | True | 54788233f3606d52a3e27a0325d7da2adbb04d43500445919ad40eaf114163d0 |
| `app/Filament/Resources/DrawResource/DrawLifecycleActions.php` | True | b5825ad7abd87002c83e1158c281adfcab294a5394e50036dffc336f0af8d62c |
| `config/lottery.php` | True | 9127a4799cb937043d7376aa5204f3ac1827976f616753fb5885e070aa027db7 |
| `tests/Feature/Draw/OwnedDrawCancellationTest.php` | True | 49b567a003009612879870bcc6423c1a644fa13c739fbd59947da1c9f0ebd74c |
| `tests/Feature/Draw/OwnedDrawCommissionCancellationTest.php` | True | 2e1ba8f984d94468d149fd807c05eddcc9edea53015a175faebbf3649b61c671 |
| `tests/Feature/Draw/OwnedDrawCancellationServerRaceTest.php` | True | 04b9dbdb3b8321ed2f63f32ac98a2cae47eff02349ecab117a020bc6b2cb2cf3 |
| `database/migrations/2026_10_17_000200_create_bet_purchase_receipts_table.php` | True | 26b2248f581c7fe239898150065572674f4f0cbe45883e528f250591bf161b6a |
| `app/Services/Betting/BetPurchaseReceipt.php` | True | b318941a9fa85181512b44a9de3998b9e17cf828afe9e9bcc5683479de7cee79 |
| `app/Services/Betting/BetPurchaseReplayService.php` | True | bbe12a8044834117240c45a03bb55d2f2b0e0a173faff5b6f4c156be85a94fdc |
| `app/Services/Betting/BetPurchaseIdempotencyService.php` | True | d7b0e916da00f812d96d190bc57fcb2c120378785c57e057197612e264b41487 |
| `tests/Feature/Betting/OwnedPurchaseReplayTest.php` | True | 439509914f0ac70a599dbabf753e212569e4a6ab529b0ca767d4f019349c2dc6 |
| `tests/Feature/Betting/OwnedPurchaseReplayServerRaceTest.php` | True | e1f5499881005a96cb7b686b48f0ea561f34ecc21feca9f24466e5cf46bde967 |
| `app/Services/Betting/BetAmendmentOwnership.php` | True | b13132ec40ecf2bd431c00258c7916ffaf524eae6d2da5bdb0f64da298e66194 |
| `app/Services/Betting/BetAmendmentPermit.php` | True | e938cfa344eedc423f087a80f26c81eeef454d4ba47ab2eb53020611dbe3dd78 |
| `app/Services/Betting/BetAmendmentReplacement.php` | True | c24926e9c7ec7dd16d9eea0957d8097d818420972c6b53055a15a6c64392946f |
| `database/migrations/2026_10_17_000100_create_bet_amendment_ownerships_table.php` | True | 1f0fb229d3e2cfd0258d3715cd6ad0f046e7a1be76f2ada37d8205a4bc7f9f97 |
| `tests/Feature/Betting/Concerns/BuildsOwnedAmendment.php` | True | 70601965821a26ceb0d3ed48fbaaac5e3435b224f90b685b319a4f6a3d3a00fd |
| `tests/Feature/Betting/OwnedBetAmendmentTest.php` | True | 287446c71da86433b93a8bc627f19cae7ee52efdfbdc03234995027db55d5011 |
| `tests/Feature/Betting/OwnedBetAmendmentServerRaceTest.php` | True | fe778a291e3a163839ea37cfd30e02c31059cd53ced65b3bf9dc857be1f26e5b |
| `app/Services/Betting/BetPurchaseService.php` | True | 700865916c6a85e1775abbf37b6a0c7a8d62e4fb24717f6fc75015a9797ee1cf |
| `app/Providers/AppServiceProvider.php` | True | fade84c3d4ced4a80bb345ffc2bd38ce980e02d4d44e2e3e26b9e31bc1c4a5a8 |
| `app/Http/Controllers/Api/V1/BetAmendmentController.php` | True | de0211cff2e1b7ff4a1db85dce97174dd49e848eee5e737fc71133353c5189fa |
| `database/migrations/2026_10_16_000600_create_agent_commission_compensations_table.php` | True | eaca846bee8fd5f3121464a980bf8bb77881d2034292ef4667b505d84ad15ada |
| `app/Services/Agent/BetCommissionCompensation.php` | True | 77a7ccae65439d0b9e094b884f5eb59b59a86275d3e32fd98f653ec16cf23869 |
| `app/Services/Agent/AgentCommissionReversalService.php` | True | 9c5135b531e3ef3a73932ff1c978eb77fa1c47ab28a56586b12aaa8b19cf3c70 |
| `app/Services/Finance/FinancialReversalService.php` | True | 5c8138c486209e424c40d72ed5645a995b3f14744c6f77d3767ae52d4d4177a7 |
| `config/agent.php` | True | d8f485b8ed02b0200f2468c93bd342fadd4ff79dc975d3350c4b700a61a8f33a |
| `tests/Feature/Agent/Concerns/BuildsPaidCommissionCancellation.php` | True | 48362a1845f953975416c407e1075eeb3766cdef127c847817bce727fc216bd5 |
| `tests/Feature/Agent/PaidCommissionCancellationTest.php` | True | d7774dd35daacf1f5fb6f6096fcfe770ccdaf1c445554aa7741ea0f15b209ee7 |
| `tests/Feature/Agent/PaidCommissionCurrentReadTest.php` | True | 11f520000d46b4fcd521578406dcc0121f54e7dccdb1ed0b02321f47808bfb66 |
| `tests/Feature/Agent/CommissionRefundReversalTest.php` | True | d29ef9c998219380baa51e83f2fe0a2ae517dce3903f9fa1601adaa0fc3d3667 |
| `tests/Feature/Agent/CommissionDrawCancellationTest.php` | True | 285a5e98f24e34140c1a94c4600cb1ad6172b71af2106121d6d973e8aa055648 |
| `database/migrations/2026_10_16_000500_create_agent_commission_payouts_table.php` | True | 4bcd88b8376b5a3aca93ed0db3321be4ce608a18f3618aacf0d824fa00622c92 |
| `app/Services/Agent/AgentCommissionPayoutProof.php` | True | 2ecc0b03ec823cddbd47cfb9db208bbf10f95b72f515027194af97e44329475c |
| `app/Services/Agent/AgentCommissionPayoutService.php` | True | 8112b1f356cd2b221ebe89e53b93d03acf0e55809db382ce95bc3edfa0145490 |
| `app/Services/Agent/AgentCommissionSettlementService.php` | True | b4ee954d55ef4be1840bf339ca293810a5a3970914b21ee3a4c99d3462afb588 |
| `app/Services/Agent/AgentSettlementService.php` | True | b57bc7115aff94b715536262a75d0bf2a325a83e1d995563914e2396d33c3a5e |
| `app/Services/Agent/AgentCommissionService.php` | True | 9d52d111122946f7359174100afa4ef989101e7740b63332bce6ce22b92aae9e |
| `tests/Feature/Agent/AgentInactiveBehaviorTest.php` | True | 3aef20083fdf247798ab2227f86e6a480d54456bca8269fd9b9abd2303845757 |
| `tests/Feature/BusinessCriticalInvariantTest.php` | True | 0bcc4300bcc2b6ced952134118c7d272a4f16bfe8be5fe9ed0d9b30599cf36fb |
| `tests/Feature/FinalProductionReadinessTest.php` | True | 927b3020264c79fa7c28c6b37c023118c206fb9e6f9b157d8136bb6df5572268 |
| `tests/Feature/Agent/OwnedCommissionPayoutTest.php` | True | e85efcfa93e316d462838658c83a3c87cc3dd373ac36454bf391fe96418811df |
| `tests/Feature/Agent/CommissionPayoutCurrentReadTest.php` | True | d60303d1c199e5c827dbe0fe5ad3453c5dda78a9ba9179670fa5d8ff9e80edb6 |
| `database/migrations/2026_10_16_000400_create_bet_commission_ownerships_table.php` | True | d47fe48c9274b30d05dbbc2dd4da129635e25100a644a89955399419f49bb9cc |
| `app/Services/Agent/BetCommissionCancellation.php` | True | d8aecb32aaf5f61d17dbfdcbf8d132ac23a0f5bda485a7cfd55bae7734e598bb |
| `app/Services/Agent/AgentCommissionAccrualService.php` | True | 3a9763f015397dbb11515f1fc5ef1e96f58129481580ae506d34ef0111fdd167 |
| `tests/Feature/Agent/Concerns/BuildsCommissionCancellation.php` | True | 44cbeb9aeb5efa0329a3270ba13a66e0dde876ab68817b74d8a115285b99fa6e |
| `tests/Feature/Agent/CoordinatedUnpaidCancellationTest.php` | True | 213494bdd197abe8c30d27d3301e9c25d7cf3a8da471b4cd7d5ae7679d76e183 |
| `tests/Feature/Agent/CommissionCancellationCurrentReadTest.php` | True | 1cac54dff5c7e937d10b71619a8f714ea9f12d2d987b83d9ebfb3ddd739fd5a1 |
| `app/Http/Controllers/Api/V1/BetCancellationController.php` | True | ff4b54b8bfa745e88d4b47ef08821e9cf7ee288c11176272d1134adcbec0497e |
| `app/Services/Betting/BetCancellationService.php` | True | 70057cac0589a3393b8ad7c757a522967f9fc90a89cbfa687f1f1102d29a535d |
| `app/Services/Betting/BetAccountingProof.php` | True | c1dbb019b7f8b84045fb31a288edc675ba71781204826a4893d204cd191d21b5 |
| `app/Services/Betting/BetPurchaseOwnership.php` | True | 6b62896da5a1ba38d35359a4c6e93f8ccb085618d47ac9f94e26cea361cb2306 |
| `app/Services/Risk/BetRiskOwnershipFloor.php` | True | b69e026d6f64efaa30ffa5f6fcbffb8ceb35e58a75340f6689e150aef71bb07a |
| `app/Services/Risk/NumberLimitEngine.php` | True | 81710d3788661f876cea6b0976181e6fad95dc66274e056d2f0ba518058a6cc7 |
| `database/migrations/2026_10_16_000310_create_bet_purchase_ownerships_table.php` | True | 0205aa6531ea17144f84906e7110c8e10bfbd8d2fbfdb26703230cc482e8e389 |
| `tests/Feature/Betting/BetCancellationOwnershipTest.php` | True | 9de235db26458e4177f96ceac0feb06a3cdf6162e9e4b068d6734538c5298967 |
| `tests/Feature/Betting/BetCancellationCurrentReadTest.php` | True | 72451faf940bdb9240d7ce6061576e52f6d5cfd54ffcdbcb3a75813c630fb397 |
| `tests/Feature/Betting/Concerns/BuildsOwnedBetPurchase.php` | True | 69138c52b97ec2685c1a2ae9ee1cbca031ca9df79738d1d1b828774f3b31ac92 |
| `app/DTOs/Finance/FinancialHoldData.php` | True | 115566183369344510ed1b6a0c643f3d0bed4eb0795724b3f7d2ea4f19d0cc8a |
| `app/Services/Finance/FinancialHoldService.php` | True | 1326a4bd6451ac8bf676e6eaf708672a57b80d3ddf46cfae3a02330b48beffa9 |
| `app/Services/Finance/FinancialHoldOwnership.php` | True | 77b82b7c10bbb14959e4a797253c40d339483e6f997ef88e9165e6f544a99ed0 |
| `database/migrations/2026_10_16_000210_create_financial_hold_claims_table.php` | True | 2014fc12b761ceac6507504e7a6738ea1d4cadc1018617adf023f43accb199bf |
| `tests/Feature/Payment/FinancialHoldOwnershipTest.php` | True | e63497e86581fde635c8e47254a42db48b7192d91f138ae94a29ec8c89996cbe |
| `tests/Feature/Payment/FinancialHoldCurrentReadTest.php` | True | cc416c7c1813bc0b0691d0c0286db4219e9b4cec1186630939e9c1657519f9c4 |
| `package.json` | True | 0a4beb91f1d0c19194c806c4e42978dae1bcd1046d3489f1e90276400c8514d0 |
| `package-lock.json` | True | e0673ae8c4e548245b3e6c560fd597b2cf9ce225fc2951b2a6884d7218cd4719 |
| `composer.lock` | True | 0bd090d01f6a7de358fc9c4fa314236a2c3beba6d1a99aedcb6b30dca8ecd39e |
| `.nvmrc` | True | a1c56e968c358e10872d64220b3ae759ffe7feaffc4da95fae425dbcca43fe44 |
| `.npmrc` | True | 7151cf397def0c2cb0ab65643701d27d335a72c90f775675b5f826bc7005818a |
| `postcss.config.js` | True | 954099ffb4984aee6a624648576b2bbf2741945596be216deb042cb320cfca0d |
| `tailwind.config.js` | True | 1e4b2b21da7e54d9913e721818c872c6418edd09d150eaa7ad7606c85e64e113 |
| `resources/css/app.css` | True | 398f21b0a60690362431ac94dc804f79409b70e1c510452ad477c87c1e0dde2d |
| `resources/css/tailwind-compat.css` | True | aa26d9ce8ea8171cdfe9b0582b99d4658902c329e1ac2dc585264db6e39cecaf |
| `resources/css/tailwind-legacy-theme.json` | True | 5568378f5c2b5db9bc02db52b9f16886b1826ec4ee4b7cbfeee20cbc6f8af016 |
| `scripts/dependency_security_gate.mjs` | True | 4d215c861d0ad4faabb08945fad048404293fc19ddb87157baef27300d2ae005 |
| `tests/dependencies/security-gate.test.mjs` | True | d64927f2986fdaf49c5e40846b1e145afb893cbea5962cb8c7db0c85c1f7b6cb |
| `tests/browser/frontend-build.helpers.mjs` | True | 2d9c7d383388651136370029f5ccefb5d85fed9a81e466131c38dcfe4885bcfb |
| `tests/browser/frontend-build.spec.mjs` | True | 3071e35ade785a65d05de2ff8f799c8149026d938bca295640185246805f163a |
| `tests/browser/fixtures/tailwind-v3-compat.json` | True | 0c2e8aecc5ff4b04d3a2d6cb50fd07f2ef514f88383aad1670e881c5e5cfa206 |
| `tests/Unit/Production/DependencyAuditBoundaryTest.php` | True | 93033647c4d96ff7b920b56bbf7f6a314c400c82944484f168525b067eee8cdc |
| `.github/workflows/ci.yml` | True | 0d9d7fe4530cd6a8426b3d8977cd428cd7ce56811bcda634a3a87e1faf05471b |
| `.github/workflows/security.yml` | True | 08d39921f4b7e48c54d7a971c34978fbcd84e7d2fb728919bd0d942468f9c4da |
| `app/Services/Finance/ReversalIntegrityGuard.php` | True | 976a71d4f71ba2dc0ade68ada9698e9bdab251f6ecd71f477de8af3d020e7acd |
| `app/Services/Finance/ExternalRefundPolicy.php` | True | 042b35e4a5efcd5fb737594a03fb28e21038182d99bf4aa30189b0ce3631eaab |
| `app/Services/Finance/RefundService.php` | True | 089729b14c7d73defe8ee66ebabf10887d0a35aa9d0380959d9f80c1d5f99eee |
| `tests/Feature/Finance/ReversalIntegrityTest.php` | True | 78649b1155fc6e962b75e51c64ec0aa6393daca0b3297a8331dab5255ef90126 |
| `tests/Feature/Payment/ManagedRefundBoundaryTest.php` | True | 3f4f8cdb2e75322c92524fd770683cbadbe1b70e216f1c4183c624e361ab1e6e |
| `tests/Feature/Payment/ReversalIntegrityServerRaceTest.php` | True | 256a3322574296c789f8472d9ac43a81af691447e45a9e4bb89e606648f54a45 |
| `tests/Feature/Payment/PaymentLedgerBalanceTest.php` | True | 4d6c24e0e8260ce9dd14b3de54eb98286f456402e9178cbb79834242f7aa57c4 |
| `app/Services/Finance/WalletService.php` | True | 0b4d5dd64654d672909c75d79b7033483a8abe60796016bf70d732f3d8386e96 |
| `tests/Feature/Payment/WithdrawalHoldFloorTest.php` | True | 92addd77070994cc3a49c748555f16eabe866f5cf19ce36677c69f9f4fe164f1 |
| `tests/Feature/Payment/WithdrawalHoldFloorServerRaceTest.php` | True | 46c577453a909216f4b293542d7755ce5b9a988c698fb5422c25ecc71923afa1 |
| `app/Services/Payment/WithdrawalCallbackApplicationService.php` | True | c9bd35fd1494fd2361b5db594cfb327218c7a009e683debcbe24309511409d77 |
| `database/migrations/2026_10_16_000220_create_withdrawal_reservations_table.php` | True | bdce5cc625d3d860daaa9b3d3d7cfc2ad6c0080b1b6a979d01668de8a53a0243 |
| `tests/Feature/Payment/WithdrawalOriginalProofTest.php` | True | 018569c85c7ca7e1f9e50a0e265955d6bcfb55262ab97608a49e07978380c4bb |
| `app/Services/Finance/ExactReconciliationReader.php` | True | 18ccc9b5273b0c3075d35b2e69983af3004c4e1cf91e502795a91f3c1170b9e0 |
| `app/Services/Finance/FinancialReconciliationService.php` | True | 940165229d80c2b91cf0a4ead83b14451b86f37ba22ea1271ca8f39aa1a0f89a |
| `app/Services/Finance/LedgerReconciliationService.php` | True | 8c8fe148e5f6d4168fa75c6417ea6320fd08d3a7d81b7de5972f007ac00ce191 |
| `tests/Support/InjectsHistoricalAccountingFaults.php` | True | 6bd808f449085d2d1e350e6f1c8d6f38dc855410981e565fb3bce720486017dd |
| `tests/Feature/Finance/FinancialReconciliationComprehensiveTest.php` | True | 94e35156c2a83c01006bb3beadf770c2864d0ed5cc42cb824568ea3863a630c4 |
| `tests/Feature/Finance/ReconciliationOriginalEvidenceTest.php` | True | 051497ad51d804c2e8919a33281202346b53617750a823e04aaedeb0c7530464 |
| `tests/Feature/Web/BrowserPaymentCallbackTest.php` | True | 606871a796570f6164247a49b6ca2c7a1653ef3717f9999b252080d52106b17b |
| `tests/Feature/FinalWholeSystemNoSkipTest.php` | True | a0cd352c2ebf5cfa41910f15ecc0e8dae4706af1ff6ea5b098286e65193ac123 |
| `app/Services/Finance/DepositIntegrityGuard.php` | True | 9e6fbef893821f6aff343195dc941b6830d188c8116a5ac231b702e9395aacbe |
| `app/Services/Payment/DepositProviderAccountService.php` | True | 7419930ab5ccf4af3deecd138e0375c7729ac68aa93393a836904a6b41049460 |
| `app/Services/Payment/FinancialProviderReferenceService.php` | True | be3c1bd7a8aab875dec043c0b7718cae1abc3ef48bea7ad47132e160dfab2a45 |
| `app/Services/Payment/DepositCallbackApplicationService.php` | True | 9b14e2dcdb65ed7f583053d3f8d0ae7849fb8cefe5cee743ed659af091e99992 |
| `app/Console/Commands/Finance/DepositReferenceAuditCommand.php` | True | ad965656f436ac8e26db6884c0ffde7cffdb98cc25bec69645c1adc1261e9df9 |
| `database/migrations/2026_10_16_000120_create_financial_provider_reference_owners_table.php` | True | 62dcb9da30136d8bf62d00f30f6d8c5fd8b7e55f8f7ca59070fb3ed01b45073f |
| `tests/Feature/Payment/DepositOriginalIntegrityTest.php` | True | b021ec6e5920fddbcedf8516bd6c9f6c51136b8e0c30dc2f80606f12647856f3 |
| `tests/Feature/Payment/DepositCallbackOriginalProofTest.php` | True | 5d2331706d439988add391dafd058cb659ff479f6d4f9a47a94ea6028f7710c3 |
| `tests/Feature/Payment/DepositReferenceAuditTest.php` | True | 8e9388f377e66a5bedb06aa7eb9e27310f1b71554ee6e7d8cdda801da0e9bfad |
| `tests/Feature/Payment/DepositReferenceServerRaceTest.php` | True | 85f889d77611da01c8dbe7e90dafa72168fe7eb209c63995414aed0d9a18e6bf |
| `app/Filament/Resources/DepositResource/DepositDecisionActions.php` | True | a05ea2d4ab4bdadca6a30624cd1b28b1c3bdeef7c3e02a971fab968057402870 |
| `app/Services/Finance/DepositCompletionService.php` | True | 3a7eafa75e52abed4aff1b0950fa3e546df97c99c0aa646dc7dc099da6dd78d4 |
| `app/Services/Finance/DepositService.php` | True | 3f5d47d352f2d1b9b30a73d465b934c389ce610fb3382e7854dce9539d1b4488 |
| `app/Services/Payment/PaymentCallbackService.php` | True | c85e8c1782d358f54a90c3c6114c278a9b5123b763fde0f10dfe53fcb28b9162 |
| `app/Services/Payment/PaymentVerificationService.php` | True | 6215ea22076088750c14d37759d2dca01a28f7df2a8fe9bf4b14936afce68f04 |
| `tests/Feature/Filament/FinanceResourcesTest.php` | True | 9d66005ffa3e24baaca98167f56093777cbfa26e10a85b0f9ba98de20f4b20e7 |
| `tests/Feature/Payment/PaymentCallbackServiceTest.php` | True | a9d22418e6da971ccfb97f80f767d0c3c2cfa87f911180ab445d45344b1cbbbd |
| `app/Services/Prize/PrizeClaimGuard.php` | True | e07324674de36b1ba1dcd333a9987ccf1483ae5fa1a7a11a59ffaa1916b341b9 |
| `app/Services/Prize/PrizeClaimService.php` | True | 3d4753a14497b4effa4b15b207c333fb302658805aa82cc4baa8ca05aabcc9d5 |
| `app/Services/Prize/ClaimWindowService.php` | True | c81787fde4e5db8b609966ebef9eb7a4d2dee61806624006bf54de21d5de974d |
| `app/Services/Prize/PrizeExpiryService.php` | True | 9b6d49ecbb5e7af9dde84474e079bba1c63b2279f4a7ffbf1768c44bea891954 |
| `app/Services/Prize/UnclaimedPrizeService.php` | True | cf318ed6729f6db6017fcdf382bc2ea1f7af6b9f7de4a37a66a511adbeeaca59 |
| `app/Services/Prize/PrizeClaimCompletionService.php` | True | 93038752b768df0ea74b0dcdbdcaf14bce623871fbca2e08e8c2692b0d9e396c |
| `app/Services/Prize/PrizeClaimValidationService.php` | True | 6ff6fc3d9f8408dfae316f7f260a8ef1e514448c6e779f3eda02ed09418fc5e1 |
| `app/Enums/PrizeClaimStatus.php` | True | 08604b19e5d2f8a33de093e4fa85614e13bce8ad5d77433bd35e5adafd051cfa |
| `app/Enums/ClaimWindowStatus.php` | True | 771f56f3ffc8bc28b64a71c004bf228b46a58ff0f91e723c837988af10a3b0e1 |
| `app/Http/Requests/Prize/CreatePrizeClaimRequest.php` | True | 51aa8c4508484006cd6a3300d14241f024e2cc17a9cfb7d85380e5f36e59e542 |
| `app/Http/Requests/Admin/ReviewPlayerPrizeClaimRequest.php` | True | c98fa438af9c49761e4d88104007e92219d094c21ac56ae24f6763cf9ee55089 |
| `app/Http/Controllers/Admin/PlayerPrizeClaimReviewController.php` | True | 0c95e82267446e4a8214636fb11544288b869d99534a3d4d1bcd0285996ad73b |
| `app/Http/Controllers/Api/V1/PrizeClaimController.php` | True | ff5db1ee12f91275d1ace738fd289c1429d4d99df3b01f46977290ef5f9ac536 |
| `app/Http/Resources/PrizeClaimResource.php` | True | 66031a8da26d1fa1475189b9e98e7e61ca684b749b920a0ff4fe5f65b492c629 |
| `resources/views/admin/player-prize-claims.blade.php` | True | 6ac8f46d741d2dd93eafd808bd468764df063bacc4650559f4029deda7548949 |
| `app/Jobs/ExpireUnclaimedPrizesJob.php` | True | 0db9be601534e67ba137733568761018641d2dfb2d37c703f27e89d13096ab7a |
| `app/Jobs/SweepUnclaimedPrizesJob.php` | True | fc4a80c2026cc94b7a932fe44807b3ecb61c9c03556925d93bbde85d4f9212d4 |
| `app/Jobs/CloseExpiredClaimWindowsJob.php` | True | 853ebbadf96869e96a88426ef402c30cac2ef72b41993ade14710a16cba66835 |
| `tests/Support/CreatesPlayerPrizeClaim.php` | True | dc6d9111ca0898494eb962c9a538ca8f6ef22dd70614232722272bdfa38ee394 |
| `tests/Feature/Payment/PlayerPrizeClaimLifecycleTest.php` | True | 94e2b37b762e48f3b8cda1d2b88b55d6cb3fc6a3bd34028f586521e2ceb60e04 |
| `tests/Feature/Payment/PlayerPrizeClaimHttpTest.php` | True | 2f21ef48123c1991d2ead0ec996160c0579cf38c0becd1bf0eb770c59c9bdc6e |
| `tests/Feature/Payment/PlayerPrizeExpiryTest.php` | True | 46ccdb0cc372bad45b99a9c2dceaa99aba465c5c79e422f26ea76461ca85ae5a |
| `tests/Feature/Payment/PlayerPrizeClaimCompletionTest.php` | True | ff2e912918c3cc465574b1fe70674e1ec0fcab306c2f03748cb9dfe72f11ee8e |
| `tests/Feature/Payment/PlayerPrizeClaimServerRaceTest.php` | True | 4fa35b45bc02965a2fb9fa7d475fef43d3f4383dcd209c6b3f73092c375212a5 |
| `tests/Feature/Api/V1/Batch7ApiSurfaceTest.php` | True | ce34bb0266e5a1163f5b6deac54306c334f76398651938e96896eff68bc8178b |
| `tests/Feature/Payment/PayoutEvidenceServerRaceTest.php` | True | f0753891623b9d8202d9a6a0ea5f5722f366716cb13ea6ecdfacbe1a5e88958c |
| `database/migrations/2026_10_15_000600_create_payout_transfer_evidence_tables.php` | True | 098ac54f2011b5232d1b4304f89bb60d52a35cbce764438b380fc6f7b07dce96 |
| `app/Services/Payment/PayoutEvidenceStore.php` | True | c0e437dc2fdfb2d80ce6c7034d7414fad4a3f7ced8e70c7bb9f86a99860788e3 |
| `app/Services/Payment/PayoutEvidenceService.php` | True | 595715069aeb37d5685d02ab46b2ee7d8b4128b52a79872a7373deffa8206bfa |
| `app/Http/Requests/Admin/UploadPayoutEvidenceRequest.php` | True | 30556ed6acd8cdc6dde3d3f19cb878e1b99f84c4a331172ed1dfc095877f7d22 |
| `app/Http/Controllers/Admin/PayoutEvidenceController.php` | True | 18bf9abefc7779df8dc00c9f3961e27653bcf83fd499f02bc8964e1be5ab74c9 |
| `app/Console/Commands/Finance/PayoutEvidenceAuditCommand.php` | True | 988aae84c87239cfcefe20b75a4b7fd3f6f8fa1cd818585ea7c5374f1f7dc73a |
| `tests/Feature/Payment/PayoutEvidenceWorkflowTest.php` | True | f4bd4189f0bf7cba23b60fddd0d706013521fb418511a66e93b4f088126f3cf9 |
| `tests/Feature/Payment/PayoutEvidenceHttpTest.php` | True | 052c2ae8e1ddd8ff2d1d1f80082208f6986a632fa31ceb6326278bec0a3ee5f4 |
| `tests/Feature/Payment/PayoutEvidenceRetentionTest.php` | True | fdc4ff8fac900c9cd99e5b8bb119b6754010e1ec0ed7b40737eed3d897551dab |
| `database/migrations/2026_10_15_000500_create_outbound_provider_references_table.php` | True | 0c15ad031e8b7d83000cdbacfe53bbb36718aee5425af83875fd35efb7b4bd97 |
| `app/Services/Payment/OutboundProviderReferenceService.php` | True | 4a6b5ed6436648400154a26b2d9ca1c6cf2b99f4152a8e0ff975a9f01fa3e6d5 |
| `app/Services/Payment/OutboundReferenceHistoryService.php` | True | 9d1a2fe6ba5d1167935d4411af89dd55cb711317e549cd5ffdeb7a1aa7bd5f23 |
| `app/Services/Payment/OutboundReferenceAuditService.php` | True | b2d49346d425f3d79d0b82c932875b7fd56ac165bfb76aac2591c6146bfec349 |
| `app/Console/Commands/Finance/OutboundReferenceAuditCommand.php` | True | 23d10cf59744f63a2e49dcfc420c0f2c17892f238869b1d619fc756115042669 |
| `tests/Feature/Payment/OutboundReferenceCrossDomainTest.php` | True | e42cc633b9a8e39174f358c83788af1debef1e932abb07f9606250dd6a875b2c |
| `tests/Feature/Payment/OutboundReferenceRegistryTest.php` | True | d15102e9cdee57364b90100c5779544eccc3b6303d41ce4a3092849a0fa52b03 |
| `tests/Feature/Payment/OutboundReferenceAuditTest.php` | True | bcdb795541e9f65b07adf4d79480d2ca500d7f6419b0ccdceef1d9c2ea851f28 |
| `tests/Feature/Payment/OutboundReferenceServerRaceTest.php` | True | 2a183b452baf4a4a105712cbb6634fe32a806fdd9c0797348747abb25fdbd3f4 |
| `database/migrations/2026_10_15_000400_create_payout_batch_members_table.php` | True | b5cc456ea72e8aaaeceea6133da9862008f44163bdd94e95e627e4af81db48be |
| `app/Services/Finance/PayoutBatchMembershipService.php` | True | 8bcff7598ea2defbdb9ea243c265219d9105a4ddd0021e38c9ae6363906aebbe |
| `app/Services/Finance/PayoutBatchService.php` | True | f6b9c99973cabea19379d6d68b9b91b54d936ae814b3f53611dfd839c56b14e9 |
| `app/Services/Finance/ApprovedWalletPayoutService.php` | True | 9a13f2369812402323cdf97127135b988560e7acd256367960b33589f981dcb9 |
| `app/Services/Finance/PayoutBatchRoutingService.php` | True | e978fd7e13db9c6f2dbfbd66acc28c08be62260fe227262d6df8851dbe00778c |
| `app/Services/Finance/PayoutApprovalIntakeService.php` | True | 9b9cb05ed6b487458b125df1a6f08d40d20f3985155c1b4df9ae1ff0ad4f496b |
| `app/Jobs/ProcessPayoutBatchJob.php` | True | b74d64548e4b297a3a499cd0f77d49981fb4d5623bc3725dc84bb1766624c429 |
| `app/Jobs/GeneratePayoutBatchJob.php` | True | ce0eb581c4b11429059a3e45dd3f39601eda51ef218038e39b1ff3a2ba51a635 |
| `app/Http/Controllers/Admin/PayoutApprovalIntakeController.php` | True | e37babf439b12ab6185989550f574df8e06930f1f3ad0b7b03bb023124b862f9 |
| `app/Http/Requests/Admin/StorePayoutApprovalRequest.php` | True | 8e26daa9b15fe9391ef8d0dd7f3261df295903c9e1ae37668a1596931d6f011c |
| `app/Http/Requests/Admin/DecidePayoutApprovalRequest.php` | True | 2d5669ff28f3e2887b510061a6e81bc9066bbcf91ea433ce8e95abb508a5483d |
| `app/Http/Resources/PayoutApprovalResource.php` | True | e28a9545d0ad955b8b0bc5ceb728a3f86b60d2ad644996f8c029ec92ce2cd799 |
| `resources/views/admin/payout-approvals.blade.php` | True | ebca9a2d1b4fe90b5b2afabba0c3eeca487d30ce1c04f99a0398572be7a63ab2 |
| `app/Services/Prize/PrizeDisbursementService.php` | True | 9077a6b4540eeeb06dfc512116ae029bb86cf5ab42af1439e67617da060c003a |
| `tests/Feature/Payment/PayoutApprovalIntakeHttpTest.php` | True | a9d5bf852a7252176779e8289bad68c662a6f045cb215c6e1a9006313ef8837b |
| `tests/Feature/Payment/PayoutBatchRoutingTest.php` | True | 610bc758634489fba3e74e7024b9c951d73934caca653a2c037be24685e999e5 |
| `tests/Feature/Payment/ApprovedWalletPayoutTest.php` | True | 495ec157f8e978f310dc06090ec75b389bcb33a005daa036b6ba2b19aeb3bb1e |
| `tests/Feature/Payment/PayoutBatchProjectionTest.php` | True | db8092c13aef8554a0b6197cf9a74cb9af6a6bb2c800ea8dea9ed7dd6dff0da4 |
| `tests/Feature/Production/QueueDispatchContractTest.php` | True | 6ed8f3949fb328c695e2f31824f5eb74cc3a4992338431b8d82b449376811e2f |
| `database/migrations/2026_10_15_000300_create_payout_transfer_references_table.php` | True | ad9f921c2bfdfd4db34d8ebd579f6ef51f47fdfbd1822573a5e5527d4db6ada1 |
| `app/Services/Finance/PayoutApprovalGuard.php` | True | 1bd6d88b6e7619b68eaccc6e40163aefa38d601aa1c1c8978df684328cf7bde7 |
| `app/Services/Finance/PayoutApprovalService.php` | True | cc2a2c45b29c8f4488b6ec3f92561515b2d1b369167b163c5046b9982885c639 |
| `app/Services/Payment/PayoutTransferAccess.php` | True | da392c0593018ce8fd6f74d73c076c48b6085712881b053caa3cf16200b34db9 |
| `app/Services/Payment/PayoutTransferGuard.php` | True | f9adc92587a273485ae2dbcaa5098a0476221060f1c827b94895d6f2465f0536 |
| `app/Services/Payment/PayoutTransferReferenceService.php` | True | c1218877189008086932747d5b11e0ac585533a5b6ef283e5ec74520cca36418 |
| `app/Services/Finance/ExternalPayoutCompletionService.php` | True | ae30a367592eb54b8cd9401c5a4a037cda458115c3197d9467c16ff3535b31f2 |
| `app/Services/Payment/PayoutTransferService.php` | True | b0e7356c3f6a972f1d000c49ea7763d80f3629012bc6ee72901015020c803070 |
| `app/Jobs/ExecutePayoutTransferJob.php` | True | ddf91c43eef5aa7bb9e42fef1830a62f51670693c27004ca4be594857a4d90d8 |
| `app/Http/Requests/Admin/ReviewPayoutTransferRequest.php` | True | 58516e4fcc884d301965481d102972195e10a31d76441594935c643ed3db3d58 |
| `app/Http/Resources/PayoutTransferResource.php` | True | 57e7274f4d9abd49ceb9cba51eab4c0df3b70e1ba4889d348d412be5580174e4 |
| `app/Http/Controllers/Admin/PayoutTransferReviewController.php` | True | 068b9230546ce18015ac3cd76955233df5ab15d81e143a947835a1dec07f8c8a |
| `resources/views/admin/payout-transfers.blade.php` | True | 3f53e3d99f8232a94e8534c5c8f5b862042c9e4c2015437b8e7c0a8749ab843d |
| `tests/Feature/Payment/PayoutApprovalBoundaryTest.php` | True | f5ae49d029c4ac9b9dd91af2ffa0a00b5132a6baf59f8fba0c9f09398505cfae |
| `tests/Feature/Payment/PayoutTransferIdentityTest.php` | True | 06cd7e9273a6e9a612183bf42d10584ceeea8de04b546ca1b7f98baeeaccfb40 |
| `tests/Feature/Payment/PayoutTransferCompletionTest.php` | True | 14c6e422408a91ab58a90472d444322e1f7d50fa8d701e1c2c0fb32b7a649bb4 |
| `tests/Feature/Payment/PayoutTransferReferenceTest.php` | True | 003b672dfa5001059deddc61c676fc17b361250a820345ec259ea5ffb5343723 |
| `tests/Feature/Payment/PayoutTransferHttpTest.php` | True | e5d4433573cc5defbeec7ab052f6aadd59c57170dd412dd7d0f6e86f88046cc0 |
| `tests/Feature/Payment/PayoutTransferJobTest.php` | True | 29330dba4c26d71c80f06f9fd0b1453a431b52811f04da789344864b244bd1e4 |
| `tests/Support/CreatesExternalPayout.php` | True | f985f6db582e076b59341ec96131771c3318cc00821d423475719c86db7e8056 |
| `database/migrations/2026_10_15_000200_create_withdrawal_provider_references_table.php` | True | ec37a89f7a8fb86b29ca75e9116d269d41c56b56a7b3aa20ccf1e14ee8a6fe1a |
| `app/Services/Payment/WithdrawalProviderAccountService.php` | True | 172cfd1690808ce0c1e897e15288c48e13fa013010e4d648b88e66f939e1bcf6 |
| `app/Services/Payment/WithdrawalProviderReferenceService.php` | True | b5cec5d3063108bba7b2fa59a6d7c4b2ad3d7e679122444755a2732a630d21d9 |
| `app/Console/Commands/Finance/WithdrawalReferenceAuditCommand.php` | True | 8fbc387052cdb21fce02784ec4c91d3cda15c734a57f488cc6e3b972a7240b93 |
| `tests/Feature/Payment/WithdrawalReferenceCrossLaneTest.php` | True | 08481cf6a7c6f54f830d015769b97f2cecc25314cbd66aad9a746c53519e32f7 |
| `tests/Feature/Payment/WithdrawalProviderAccountTest.php` | True | d915ae6f6c2f6380d23d339e61bb52fa151b4094a8f1d3b5a5bf5529013bf9e1 |
| `tests/Feature/Payment/WithdrawalProviderReferenceTest.php` | True | bfcb7c70980bfb08ffe0ab9e5c265ffdd0dac025924340e2dde032ba2634924a |
| `tests/Feature/Payment/WithdrawalReferenceAuditTest.php` | True | 493f196cb8293eea83901a82d9b69c2073b26a2f7351c1e8e4a1ec16ad1b35d7 |
| `tests/Feature/Payment/WithdrawalReferenceLegacyCompatibilityTest.php` | True | 77107f85ecead2bdf445f6856c5546c56d3a6e1c433792d8d32c0256cc8f4d63 |
| `tests/Feature/Concurrency/WithdrawalReferenceServerRaceTest.php` | True | c8e5a1b6ea98b0195133e29465658f67d2139b6627df7fa1c8cc70ec3be5ef3b |
| `app/Services/Finance/WithdrawalCompletionService.php` | True | 8591f415e85b698e7e774198b86eb232ed96a5dd23ef696a0147d492405a7f8c |
| `config/payment.php` | True | 9418ffbfa46c55a47096d16667712f004300e81768c7ff6bf561aa3542b63bff |
| `database/migrations/2026_10_15_000100_create_withdrawal_reconciliations_table.php` | True | c18a6e247cccbc0e310b5e103c52a151744a35588207c46126afefb5ea6dc565 |
| `app/Enums/WithdrawalReconciliationOutcome.php` | True | 3456fc3de98489eebf14de0e49ff96553e4225860e654a5c3b7915d08b55f68b |
| `app/Models/WithdrawalReconciliation.php` | True | 6dfdd9ed5c3e2046d9137eb1f30e47660a7d870332dbea5dc5d5ad300c0af77f |
| `app/DTOs/Payment/WithdrawalReconciliationFacts.php` | True | d30eaaeb2e9bbc63da30c5b5280759b1cb88fda1cf8325000b8e3e1ea5fbb4dd |
| `app/Exceptions/WithdrawalReconciliationException.php` | True | a1bfe6434676a14a084200e69b6ab9ef0ff263ec589fa07d92ab73508d30b099 |
| `app/Services/Payment/WithdrawalReconciliationAccess.php` | True | 54c9874a1cde754dcc51cf8506cc35ccdf655bb77536562ad2a96a16a265e729 |
| `app/Services/Payment/WithdrawalReconciliationEvidenceStore.php` | True | 99c540c2fb55bfa2f60cd1c3106f496fa875b75093ca3527c89bbfc0656cb3eb |
| `app/Services/Payment/WithdrawalReconciliationService.php` | True | 80527741b5cb15c85ff2300c47f9c3fb7966cec3f992f95e554488d6480fe74f |
| `app/Http/Resources/WithdrawalReconciliationResource.php` | True | 805172a3c94da11caaedf8814fbf98d8078c7423fb885f3e59a597a6b59678bb |
| `app/Http/Requests/Admin/StoreWithdrawalReconciliationRequest.php` | True | 5bc27286083a277b9196b3c6b7f9f3b64070cb6bd437ef299aca1bc6541a9266 |
| `app/Http/Requests/Admin/DecideWithdrawalReconciliationRequest.php` | True | a5459f7eb182476077c6e2d91bc3f8baf45b10baf73b688a694d47113a46b9d1 |
| `app/Http/Controllers/Admin/WithdrawalReconciliationController.php` | True | 8c44ac60a4016bd920690caf818ec49c743c78120656b9a234d8a91da3a803ff |
| `config/permission.php` | True | 162dbf47cab5e497cbd32505ebe4568fc47d59801b93a8703efd74fd4a90221d |
| `resources/views/admin/withdrawal-reconciliation.blade.php` | True | 81bdf23a9bed8b21138aff17ddb1bac981547565b897c7933e26ef6e05e319d9 |
| `tests/Feature/Payment/WithdrawalReconciliationServiceTest.php` | True | b73f34f97c0ef4150b71c4d3f358f32a34d1a3e2cf075a7f7146446bbd729288 |
| `tests/Feature/Payment/WithdrawalReconciliationHttpTest.php` | True | 064985754dd2d456c0a024c4d156b79d538110e4a2cca69834d3b6d7bcd4423f |
| `tests/Feature/Concurrency/WithdrawalReconciliationServerRaceTest.php` | True | 262cdf4b0accc5ce34548d917525d58001af1f72b7222c6d3a0ed7798c8fa53c |
| `app/DTOs/Payment/GatewayWithdrawalResponse.php` | True | c25a233b85356201cc428b5a7cd5db3453c10452cb09a3fa3eca8ec05a3afbf7 |
| `app/Services/Payment/WithdrawalDisbursementService.php` | True | 3e50f5b8693361134446f3bc2587ebfcd45ec7047d74cfc45d662d81e1356587 |
| `app/Services/Payment/Contracts/PaymentGatewayInterface.php` | True | 36bc6ef2b9b4cc9e5a90803330b7fd6711db74d24b55b8884fc433ec4bb1f979 |
| `app/Jobs/Payment/DisburseWithdrawalJob.php` | True | 6e544f35fb3dff267e24dacb9f18638b5fe6cb2c3492ae76227afc7d322ecda4 |
| `app/Console/Commands/Finance/WithdrawalDispatchStatusCommand.php` | True | e1afa396d1be291ef72d4afd4da74374e755bafd4b53860ef3a9453c29016e44 |
| `tests/Feature/Payment/WithdrawalDispatchSafetyTest.php` | True | 18211486f3721b37a80433543687b78e8c744cfff39485b033a801d8d45ad416 |
| `tests/Feature/Payment/WithdrawalDispatchStatusTest.php` | True | b3becb827827eda611b4ff062c66df998215a7f5d8d3b89f983398605946c0b3 |
| `tests/Feature/Queue/ProductionQueueComprehensiveTest.php` | True | 5b018d53c1d4cad5e90a9e885b92d32fe997f78dbaecd3e71f451b93f2ebc9dc |
| `tests/Support/SyntheticWithdrawalGateway.php` | True | d030cb1a3e207878fcba76e5d3a68860a86ca080cabbb2743ed0b3cf3c0bec63 |
| `tests/Support/WithdrawalDispatchProbe.php` | True | 666ed64695d660d95013e5a7afc4c35ea99196f92e785f7448ae00927cec9992 |
| `tests/Feature/Concurrency/WithdrawalDispatchRecoveryTest.php` | True | cf44d186735492fe3aa23499c31097e6979a9e20787c505b0dcbe7857c02d742 |
| `tests/Feature/Concurrency/WithdrawalDispatchServerRaceTest.php` | True | b428db07f6074dc0fda585abef8a112293df2de55935a307921d57d39720da95 |
| `scripts/run_withdrawal_dispatch_recovery.py` | True | 894fa22aa354f91c75987fcca61e2de57cce2ca228e3bf85c8e2be53289c3fa5 |
| `scripts/test_run_withdrawal_dispatch_recovery.py` | True | 7c1941a547076c0ae8c58ea0ff16dd0e973852c292f71a77b4fb862035b91552 |
| `tests/Unit/Payment/BkashGatewayTest.php` | True | a62ed1cb1292cc2a76377eda4464d5963d407eb71dc0efec3754d90acdeaf293 |
| `tests/Unit/Payment/NagadGatewayTest.php` | True | 756966ee3f97675cec88bc10d7709ac55c75cd3c3efed1e76c7ed331f3a1ad1c |
| `tests/Unit/Payment/CryptoGatewayTest.php` | True | 4e98f5e58502fb463b56ce2eb77fab785d3f3395c63a24c1eb0622ed8ba22c66 |
| `app/DTOs/Payment/WebhookMoneyFacts.php` | True | 74cce52d9fcada08fcf19ba003bdc4d9382e77cac6c6e8f2c5f59d0bbcd5eb0d |
| `app/Services/Payment/PaymentWebhookBindingService.php` | True | 0bdfda7842dfdc27cc945a2e151ed39e90b8ac777ea7812ecb09cc6a7db739fb |
| `app/Services/Payment/PaymentWebhookService.php` | True | 58861acaed718823a53e088c75c6a4ffb1914c592460ca74ac9a426befa4b6d3 |
| `app/Services/Payment/Drivers/StripeGateway.php` | True | ab954ab992652ffe4404427724affe2d2da7425237a3bd76f493a9c8f1d4545b |
| `app/Services/Payment/Drivers/BkashGateway.php` | True | 4dfdc9a08bf7789baf370564c3464425db47179ea8d025ee4fde9612bd8a0bf4 |
| `app/Services/Payment/Drivers/NagadGateway.php` | True | b8b118853f9ad9f13dc52cbabc48aa5302f4ae14967a0de9032b9321e5f4a444 |
| `app/Services/Payment/Drivers/CryptoGateway.php` | True | 57168b5c6f31db359f2ce84d7eead8e9be66c1d1c1c1e2aa86dd0caeb59bb53e |
| `tests/Feature/Payment/WebhookObligationBindingTest.php` | True | 57608464791706a9adbae4ec8f9e839c248a182710ce83316da74212530c749a |
| `tests/Feature/Payment/WebhookMoneyFactsTest.php` | True | 9fd90c98ebfc133fac3feaef2aeee50001a24194976b118ae36e854ac0078143 |
| `tests/Feature/Payment/PaymentWebhookSignatureVerificationTest.php` | True | e6f0bb00ade9506624a76068c7e5779db6cd880030d18ed2ed115d396ece936e |
| `tests/Feature/Concurrency/WebhookObligationServerRaceTest.php` | True | 8ef6e9d7922c9786113d93c3457b1bbb1b2fccb6c96ac66349200a859ea9e565 |
| `scripts/test_run_financial_concurrency.py` | True | c7cf9736a7569bf942b7ff5316cbbc7607b675efeb2ccc73b66b37051a562aeb |
| `routes/web.php` | True | bb7942c215fb41bcd778bd3fa964d7a1a2c1259f8be5301453394a656ec66211 |
| `routes/api.php` | True | dec6a4660f939ccf91374e719ae1a90bb746b0d0a59ae7cf628c2df6a30a1736 |
| `app/Console/Commands/Lottery/SettleDrawChunkedCommand.php` | True | 9dbb5497911e25abff5289dbdc7dbc88ac90e4800ce4f2a4aa6f75fddf099f32 |
| `tests/Support/OwnsPrivateRedis.php` | True | e5b7049a15d6b25456f58c91c5b5d0b3c46b50c64d521e424a67dc62c0c92f1b |
| `tests/Support/RedisSettlementProbe.php` | True | 2eea910608533f0175031641926cb83ba75dfb35f89cda267a2e99a67245122c |
| `tests/Feature/Concurrency/RedisSettlementRecoveryTest.php` | True | 7cc77843b5dbeac830196524f3771909cabd678b83e10b060fe1ecba8231d5d8 |
| `tests/Feature/Draw/SettlementQueueHandoffTest.php` | True | 14df1ca532c1b6a99b2e1cdb8bab04315b52116e1d58e345e51168093a5b34cc |
| `scripts/run_redis_settlement_recovery.py` | True | e842d098f37612626cc5be958c626acc7989f937eea930ff987faf03b9f75282 |
| `scripts/test_run_redis_settlement_recovery.py` | True | 28f96c059574d06ff3bdcec038f31aca8ff4f2414b35788771ddef941f966a2e |
| `app/Jobs/Draw/Concerns/HasSettlementRecoveryWindow.php` | True | ae237c5d71101505e8c0844fc1766399f146dab57a8ad423803f21f052bc6c24 |
| `app/Jobs/Draw/ProcessPrizeSettlementJob.php` | True | 4d4ba2480ff50927f2b788a6059f6ee569844e99097dcce6f33d32b88adb847e |
| `app/Jobs/Draw/SettleDrawChunkJob.php` | True | 98cde07a78876bd1dcb81457c1b0ff8bb1a0ff8f3c3e4aa8f6fe9eac3acd3cf9 |
| `app/Jobs/Draw/FinalizeDrawSettlementJob.php` | True | 107e3aeff77d7e7d9500eca4557937c27496739b0bb3b4b02ff02f2c007d30f0 |
| `tests/Support/SettlementRecoveryProbe.php` | True | ff0cafd99907295ce0c1cea791e3c0ec1801164ea4f5e378fe56e4581a62ff88 |
| `tests/Feature/Concurrency/SettlementWorkerRecoveryTest.php` | True | 3deaf6e32140c87f55fdb23e18565ef0f6419231f451e649d68af286cec09ca9 |
| `tests/Feature/Queue/SettlementRecoveryPolicyTest.php` | True | 28e1cdfa69d3adeec6aa9fd8236d8733fdd40397535202554ca48833088f4802 |
| `scripts/run_settlement_recovery.py` | True | 85c4913634258d53b95afb05b2e519eb79d8018702f417af6b444dc680463cb0 |
| `scripts/test_run_settlement_recovery.py` | True | 401a62158c9c6d1fcb1ae1bc51fda85730eb571bf22b927eb1d1df239fea7cd8 |
| `scripts/run_financial_concurrency.py` | True | 20e94ff9febb15e6ca0a126f43b09735ee8823740feb855b4937ffcde54e4928 |
| `app/Http/Controllers/Admin/LottoFinExecutiveDashboardController.php` | True | 4a621435782beaa4c3f2cdbb2722384fb32b4879246c27d9e4927aac8003ce36 |
| `app/Http/Controllers/Admin/ReleaseOperationsController.php` | True | 4cf99fe90f5b37b369be416a4a0c1aaf881a83ab6a2ce957e26e48cd394b8816 |
| `app/Console/Commands/GloImportResult.php` | True | ca7af24ca3da4c604bd1de29658698e4a92edf7c4b30224546f7fca12489da62 |
| `app/Http/Controllers/Api/V1/DrawResultController.php` | True | f58fbc8da6256027e6588d7121c2d67a71f259566f53aa068a13a17f9c106ed2 |
| `app/Services/Lottery/GloResultImportService.php` | True | 87a436621508908031f2c07bca14372ec0953a49e9f63a9f6d31eb96d37fe3b3 |
| `app/Services/Draw/DrawResultIngestionService.php` | True | d7f71b0533f5f107a18a347c49b76a46496ef3e97dedaed3a14fd0e3f214d63b |
| `app/Services/Draw/DrawResultConfirmationService.php` | True | 71fb8fd40d47ada8999740a23b198afdf996e81f0b41b38d8ec8a362357eef54 |
| `app/Services/Draw/DrawResultPublicationService.php` | True | f06094d06ceaa2e110796a12e442bcecb82f1bbb8fd389caf3a78a98e1dfae5b |
| `scripts/pages_251_350_audit_matrix.py` | True | 99e937a77be452e332ce99ec4551fe15ca6cd9c78876828c8b3bd5a6665b0dc2 |
| `security/weekly-result-integrity/Cargo.toml` | True | 3373498fda3faa70e69d1b0dcf1732cf56e1465c48f561d8aae7159445f38f8f |
| `security/weekly-result-integrity/Cargo.lock` | True | afbe3e911b46d630c15b759560e09b78598c369d464de7f6958ee6877d102bbe |
| `security/weekly-result-integrity/src/main.rs` | True | eec82b8eae6d044b68766351a431a20c862b0cf552e3bbd15cf2f1e18da66042 |
| `security/weekly-result-integrity/src/lib.rs` | True | 35405fd9e10865b29027751176b853e2e0d76b162d295f3553c1d489e7d424ba |
| `security/weekly-result-integrity/src/canonical.rs` | True | 44771f05454bb127631fbe61b857def8a8c447cd07b639996d57bfef781dab66 |
| `security/weekly-result-integrity/src/error.rs` | True | 5d4dd6b0f7c66f020c04ea454a494a1583a67224b325222a8729b9bfc19b8a3f |
| `security/weekly-result-integrity/tests/strict_signature.rs` | True | 7abca792cd49cc088e4000e3b7066d968f75f69380aefe08437112bc4ddaf79f |
| `security/weekly-result-integrity/tests/cli_boundary.rs` | True | 1baf3c757e69bd8b5e11d91dcc5ec05bdc05f26cc24ca105cb3469ec79843580 |
| `app/Services/Lottery/WeeklyResultIntegrityService.php` | True | 19f81767b53259b63c7f0ebd94949b4465a5d73ace7f3757d8a07e9ccad2c324 |
| `app/Services/Lottery/Support/AbstractLotteryImportService.php` | True | c0da66a13cf44823f7f3f4b4b26af1fcdf790da3ffde840e85dbd4b4fe3dc3ef |
| `tests/Unit/Lottery/WeeklyIntegrityProtocolTest.php` | True | 5ea393f7df5aebaced838f3cef49125c0bd4f1f09e5bf7d116487f2b25fb24d6 |
| `tests/Feature/WeeklyLottery/WeeklyNativeIntegrityBoundaryTest.php` | True | de6ff4f060d5e22f651f3c00e7a050f5be13ede61a30495e2a00147d6d694ae4 |
| `security/weekly-result-integrity/tests/integrity.rs` | True | f69d2c6e918bef6ba802e7988e57d5f179b2a348b81834bd2dc454b87727f00e |

## Requested page-slot inventory

| Page | Declared title | Mapping status | Runtime | Financial execution |
|---|---|---|---|---|
| 77 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 78 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 79 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 80 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 81 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 82 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 83 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 84 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 85 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 86 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 87 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 88 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 89 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 90 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 91 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 92 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 93 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 94 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 95 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 96 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 97 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 98 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 99 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 100 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 101 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 102 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 103 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 104 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 105 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 106 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 107 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 108 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 109 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 110 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 111 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 112 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 113 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 114 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 115 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 116 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 117 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 118 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 119 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 120 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 121 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 122 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 123 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 124 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 125 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 126 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 127 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 128 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 129 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 130 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 131 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 132 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 133 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 134 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 135 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 136 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 137 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 138 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 139 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 140 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 141 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 142 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 143 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 144 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 145 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 146 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 147 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 148 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 149 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 150 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 151 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 152 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 153 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 154 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 155 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 156 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 157 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 158 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 159 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 160 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 161 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 162 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 163 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 164 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 165 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 166 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 167 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 168 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 169 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 170 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 171 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 172 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 173 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 174 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 175 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 176 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 177 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 178 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 179 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 180 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 181 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 182 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 183 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 184 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 185 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 186 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 187 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 188 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 189 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 190 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 191 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 192 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 193 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 194 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 195 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 196 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 197 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 198 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 199 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 200 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 201 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 202 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 203 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 204 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 205 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 206 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 207 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 208 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 209 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 210 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 211 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 212 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 213 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 214 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 215 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 216 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 217 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 218 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 219 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 220 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 221 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 222 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 223 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 224 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 225 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 226 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 227 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 228 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 229 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 230 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 231 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 232 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 233 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 234 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 235 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 236 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 237 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 238 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 239 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 240 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 241 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 242 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 243 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 244 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 245 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 246 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 247 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 248 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 249 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 250 | No authoritative page-to-route mapping | NOT_CONFIGURED | NOT_VERIFIED | NOT_VERIFIED |
| 251 | Runtime Environment Bootstrap | DECLARED_RECIPE_ONLY | NOT_VERIFIED | NOT_VERIFIED |
| 252 | Dependency Installation Verification | DECLARED_RECIPE_ONLY | NOT_VERIFIED | NOT_VERIFIED |
| 253 | Laravel Boot Verification | DECLARED_RECIPE_ONLY | NOT_VERIFIED | NOT_VERIFIED |
| 254 | Database Connection Verification | DECLARED_RECIPE_ONLY | NOT_VERIFIED | NOT_VERIFIED |
| 255 | Migration Baseline | DECLARED_RECIPE_ONLY | NOT_VERIFIED | NOT_VERIFIED |
| 256 | Seeder Safety Audit | DECLARED_RECIPE_ONLY | NOT_VERIFIED | NOT_VERIFIED |
| 257 | Factory and Fixture Audit | DECLARED_RECIPE_ONLY | NOT_VERIFIED | NOT_VERIFIED |
| 258 | Database Constraint Audit | DECLARED_RECIPE_ONLY | NOT_VERIFIED | NOT_VERIFIED |
| 259 | Database Transaction Audit | DECLARED_RECIPE_ONLY | NOT_VERIFIED | NOT_VERIFIED |
| 260 | Concurrency Test Harness | DECLARED_RECIPE_ONLY | NOT_VERIFIED | NOT_VERIFIED |
| 261 | Wallet Integrity Activation | DECLARED_RECIPE_ONLY | NOT_VERIFIED | NOT_VERIFIED |
| 262 | Wallet Ledger Balance Rebuild | DECLARED_RECIPE_ONLY | NOT_VERIFIED | NOT_VERIFIED |
| 263 | Wallet Double-Spend Test | DECLARED_RECIPE_ONLY | NOT_VERIFIED | NOT_VERIFIED |
| 264 | Wallet Hold and Release | DECLARED_RECIPE_ONLY | NOT_VERIFIED | NOT_VERIFIED |
| 265 | Wallet Reservation Expiry | DECLARED_RECIPE_ONLY | NOT_VERIFIED | NOT_VERIFIED |
| 266 | Financial Transaction Idempotency | DECLARED_RECIPE_ONLY | NOT_VERIFIED | NOT_VERIFIED |
| 267 | Ledger Posting Contract | DECLARED_RECIPE_ONLY | NOT_VERIFIED | NOT_VERIFIED |
| 268 | Ledger Reversal Contract | DECLARED_RECIPE_ONLY | NOT_VERIFIED | NOT_VERIFIED |
| 269 | Financial Reconciliation Execution | DECLARED_RECIPE_ONLY | NOT_VERIFIED | NOT_VERIFIED |
| 270 | Reconciliation Exception Lifecycle | DECLARED_RECIPE_ONLY | NOT_VERIFIED | NOT_VERIFIED |
| 271 | Deposit Runtime Flow | DECLARED_RECIPE_ONLY | NOT_VERIFIED | NOT_VERIFIED |
| 272 | Deposit Duplicate Callback | DECLARED_RECIPE_ONLY | NOT_VERIFIED | NOT_VERIFIED |
| 273 | Payment Callback Ownership | DECLARED_RECIPE_ONLY | NOT_VERIFIED | NOT_VERIFIED |
| 274 | Payment Signature Validation | DECLARED_RECIPE_ONLY | NOT_VERIFIED | NOT_VERIFIED |
| 275 | Payment Provider Error Matrix | DECLARED_RECIPE_ONLY | NOT_VERIFIED | NOT_VERIFIED |
| 276 | Payment State Machine | DECLARED_RECIPE_ONLY | NOT_VERIFIED | NOT_VERIFIED |
| 277 | Payment Event Persistence | DECLARED_RECIPE_ONLY | NOT_VERIFIED | NOT_VERIFIED |
| 278 | Payment Webhook Queue | DECLARED_RECIPE_ONLY | NOT_VERIFIED | NOT_VERIFIED |
| 279 | Payment Dead-Letter Processing | DECLARED_RECIPE_ONLY | NOT_VERIFIED | NOT_VERIFIED |
| 280 | Payment Replay | DECLARED_RECIPE_ONLY | NOT_VERIFIED | NOT_VERIFIED |
| 281 | Withdrawal Runtime Flow | DECLARED_RECIPE_ONLY | NOT_VERIFIED | NOT_VERIFIED |
| 282 | Withdrawal Duplicate Submission | DECLARED_RECIPE_ONLY | NOT_VERIFIED | NOT_VERIFIED |
| 283 | Withdrawal KYC Gate | DECLARED_RECIPE_ONLY | NOT_VERIFIED | NOT_VERIFIED |
| 284 | Withdrawal Self-Exclusion and Restriction | DECLARED_RECIPE_ONLY | NOT_VERIFIED | NOT_VERIFIED |
| 285 | Withdrawal Failure Recovery | DECLARED_RECIPE_ONLY | NOT_VERIFIED | NOT_VERIFIED |
| 286 | Withdrawal Completion Reconciliation | DECLARED_RECIPE_ONLY | NOT_VERIFIED | NOT_VERIFIED |
| 287 | Bet Purchase Runtime Activation | DECLARED_RECIPE_ONLY | NOT_VERIFIED | NOT_VERIFIED |
| 288 | Bet Price Authority | DECLARED_RECIPE_ONLY | NOT_VERIFIED | NOT_VERIFIED |
| 289 | Bet Currency Authority | DECLARED_RECIPE_ONLY | NOT_VERIFIED | NOT_VERIFIED |
| 290 | Bet Limit Enforcement | DECLARED_RECIPE_ONLY | NOT_VERIFIED | NOT_VERIFIED |
| 291 | Bet Concurrency | DECLARED_RECIPE_ONLY | NOT_VERIFIED | NOT_VERIFIED |
| 292 | Bet Idempotency | DECLARED_RECIPE_ONLY | NOT_VERIFIED | NOT_VERIFIED |
| 293 | Bet Failure Rollback | DECLARED_RECIPE_ONLY | NOT_VERIFIED | NOT_VERIFIED |
| 294 | Ticket Issuance Runtime | DECLARED_RECIPE_ONLY | NOT_VERIFIED | NOT_VERIFIED |
| 295 | Ticket Ownership | DECLARED_RECIPE_ONLY | NOT_VERIFIED | NOT_VERIFIED |
| 296 | Ticket Share and QR Security | DECLARED_RECIPE_ONLY | NOT_VERIFIED | NOT_VERIFIED |
| 297 | Ticket Verification Runtime | DECLARED_RECIPE_ONLY | NOT_VERIFIED | NOT_VERIFIED |
| 298 | Draw Open and Close Automation | DECLARED_RECIPE_ONLY | NOT_VERIFIED | NOT_VERIFIED |
| 299 | Draw State Machine | DECLARED_RECIPE_ONLY | NOT_VERIFIED | NOT_VERIFIED |
| 300 | Draw Locking and Cutoff | DECLARED_RECIPE_ONLY | NOT_VERIFIED | NOT_VERIFIED |
| 301 | Result Import Runtime | DECLARED_RECIPE_ONLY | NOT_VERIFIED | NOT_VERIFIED |
| 302 | Result Provenance Persistence | DECLARED_RECIPE_ONLY | NOT_VERIFIED | NOT_VERIFIED |
| 303 | Result Duplicate Import | DECLARED_RECIPE_ONLY | NOT_VERIFIED | NOT_VERIFIED |
| 304 | Result Conflict Detection | DECLARED_RECIPE_ONLY | NOT_VERIFIED | NOT_VERIFIED |
| 305 | Result Certification | DECLARED_RECIPE_ONLY | NOT_VERIFIED | NOT_VERIFIED |
| 306 | Result Publication Gate | DECLARED_RECIPE_ONLY | NOT_VERIFIED | NOT_VERIFIED |
| 307 | Result Correction Policy | DECLARED_RECIPE_ONLY | NOT_VERIFIED | NOT_VERIFIED |
| 308 | Leading-Zero Integrity | DECLARED_RECIPE_ONLY | NOT_VERIFIED | NOT_VERIFIED |
| 309 | National Lottery Data Lane | DECLARED_RECIPE_ONLY | NOT_VERIFIED | NOT_VERIFIED |
| 310 | Weekly Lottery Data Lane | DECLARED_RECIPE_ONLY | NOT_VERIFIED | NOT_VERIFIED |
| 311 | Mega and Other Product Data Lane | DECLARED_RECIPE_ONLY | NOT_VERIFIED | NOT_VERIFIED |
| 312 | PCSO Data Lane | DECLARED_RECIPE_ONLY | NOT_VERIFIED | NOT_VERIFIED |
| 313 | GLO L6 Data Lane | DECLARED_RECIPE_ONLY | NOT_VERIFIED | NOT_VERIFIED |
| 314 | GLO L6 Purchase Contract | DECLARED_RECIPE_ONLY | NOT_VERIFIED | NOT_VERIFIED |
| 315 | GLO L6 Ticket Range | DECLARED_RECIPE_ONLY | NOT_VERIFIED | NOT_VERIFIED |
| 316 | GLO L6 Pricing Authority | DECLARED_RECIPE_ONLY | NOT_VERIFIED | NOT_VERIFIED |
| 317 | GLO Prize Allocation | DECLARED_RECIPE_ONLY | NOT_VERIFIED | NOT_VERIFIED |
| 318 | GLO Unsold Ticket Prize Scaling | DECLARED_RECIPE_ONLY | NOT_VERIFIED | NOT_VERIFIED |
| 319 | GLO Claim Window | DECLARED_RECIPE_ONLY | NOT_VERIFIED | NOT_VERIFIED |
| 320 | GLO Prize Claim Creation | DECLARED_RECIPE_ONLY | NOT_VERIFIED | NOT_VERIFIED |
| 321 | GLO Claim Duplicate | DECLARED_RECIPE_ONLY | NOT_VERIFIED | NOT_VERIFIED |
| 322 | GLO Claim Age Verification | DECLARED_RECIPE_ONLY | NOT_VERIFIED | NOT_VERIFIED |
| 323 | GLO Claim KYC | DECLARED_RECIPE_ONLY | NOT_VERIFIED | NOT_VERIFIED |
| 324 | GLO Payment Hold | DECLARED_RECIPE_ONLY | NOT_VERIFIED | NOT_VERIFIED |
| 325 | GLO Ticket Freeze Runtime | DECLARED_RECIPE_ONLY | NOT_VERIFIED | NOT_VERIFIED |
| 326 | GLO Freeze Release | DECLARED_RECIPE_ONLY | NOT_VERIFIED | NOT_VERIFIED |
| 327 | GLO Frozen Winner Processing | DECLARED_RECIPE_ONLY | NOT_VERIFIED | NOT_VERIFIED |
| 328 | GLO Public Result Publication | DECLARED_RECIPE_ONLY | NOT_VERIFIED | NOT_VERIFIED |
| 329 | Prize Matching Engine | DECLARED_RECIPE_ONLY | NOT_VERIFIED | NOT_VERIFIED |
| 330 | Prize Settlement Engine | DECLARED_RECIPE_ONLY | NOT_VERIFIED | NOT_VERIFIED |
| 331 | Prize Payout Idempotency | DECLARED_RECIPE_ONLY | NOT_VERIFIED | NOT_VERIFIED |
| 332 | Prize Payout Failure Recovery | DECLARED_RECIPE_ONLY | NOT_VERIFIED | NOT_VERIFIED |
| 333 | Agent Commission Runtime | DECLARED_RECIPE_ONLY | NOT_VERIFIED | NOT_VERIFIED |
| 334 | Agent Commission Duplicate | DECLARED_RECIPE_ONLY | NOT_VERIFIED | NOT_VERIFIED |
| 335 | Agent Settlement Runtime | DECLARED_RECIPE_ONLY | NOT_VERIFIED | NOT_VERIFIED |
| 336 | Agent Referral Attribution | DECLARED_RECIPE_ONLY | NOT_VERIFIED | NOT_VERIFIED |
| 337 | Agent Owner Isolation | DECLARED_RECIPE_ONLY | NOT_VERIFIED | NOT_VERIFIED |
| 338 | Notification Delivery Runtime | DECLARED_RECIPE_ONLY | NOT_VERIFIED | NOT_VERIFIED |
| 339 | Notification Idempotency | DECLARED_RECIPE_ONLY | NOT_VERIFIED | NOT_VERIFIED |
| 340 | Notification Preference Enforcement | DECLARED_RECIPE_ONLY | NOT_VERIFIED | NOT_VERIFIED |
| 341 | Support Case Contract | DECLARED_RECIPE_ONLY | NOT_VERIFIED | NOT_VERIFIED |
| 342 | Support Case Creation | DECLARED_RECIPE_ONLY | NOT_VERIFIED | NOT_VERIFIED |
| 343 | Support Case Owner Isolation | DECLARED_RECIPE_ONLY | NOT_VERIFIED | NOT_VERIFIED |
| 344 | Support Case Reply | DECLARED_RECIPE_ONLY | NOT_VERIFIED | NOT_VERIFIED |
| 345 | Support Case Escalation | DECLARED_RECIPE_ONLY | NOT_VERIFIED | NOT_VERIFIED |
| 346 | Support SLA and Age Projection | DECLARED_RECIPE_ONLY | NOT_VERIFIED | NOT_VERIFIED |
| 347 | Security Event Persistence | DECLARED_RECIPE_ONLY | NOT_VERIFIED | NOT_VERIFIED |
| 348 | MFA Runtime | DECLARED_RECIPE_ONLY | NOT_VERIFIED | NOT_VERIFIED |
| 349 | Session Revocation | DECLARED_RECIPE_ONLY | NOT_VERIFIED | NOT_VERIFIED |
| 350 | Final Runtime Acceptance Gate | DECLARED_RECIPE_ONLY | NOT_VERIFIED | NOT_VERIFIED |

## Observed architecture and limits

The dashboard has authorized KYC review and reconciliation delegates; it is not entirely read-only. Its result/import projections do not import or publish results.
GloImportResult is the dedicated permission-checked CLI intake. DrawResultController delegates ingestion/confirmation/publication to the controlled domain workflow.
Source presence and names are not evidence of successful route dispatch, permissions, money execution, official authenticity or browser behavior.
No deployment, production migration, queue drain, merchant call or payout is executed by this inventory tool.
