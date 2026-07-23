# Production readiness — SEOAuto SEO Helper `v1.2.0` (checklist only)

**Phạm vi tài liệu này:** chuẩn bị canary / stable.  
**Không** deploy production, **không** publish stable, **không** dùng R2/key/token staging cho production.

Baseline artifact đã PASS: tag **`v1.2.0-rc.2`** (`a9efca7`).  
UI simplify (không đổi updater core): commit **`3e6da59`** trên `feature/seo-helper-phase2-backup-rollback`.

---

## 1. Credential & storage production (riêng staging)

| Mục | Yêu cầu | Trạng thái inventory gần nhất |
|-----|---------|-------------------------------|
| R2 bucket production | Bucket **mới** (vd. `seoauto-plugin-releases`) — **không** dùng `seoauto-plugin-staging` | **BLOCKER** — `R2_BUCKET` absent trên prod `env.local` |
| R2 Access Key production | Key riêng; không tái dùng key staging / key đã lộ | **BLOCKER** — `R2_ACCESS_KEY_ID` absent prod |
| `WP_PLUGIN_CI_RELEASE_TOKEN` | Token CI production riêng | **BLOCKER** — absent prod |
| `WP_PLUGIN_RELEASE_SIGNING_KEY` | Signing key production riêng | **BLOCKER** — absent prod |
| `WP_PLUGIN_STORAGE_BACKEND=r2` + endpoint/prefix/TTL | Chỉ trong prod `env.local` | **BLOCKER** |
| GitHub Environment `plugin-release-stable` | Required reviewers; secrets trỏ **prod** khi publish | Có workflow; secrets hiện phục vụ staging |

Staging đã có đủ `R2_*` + `WP_PLUGIN_*` — **chỉ** để test; không copy sang prod.

---

## 2. SaaS production + feature gate `content_ops`

| Mục | Yêu cầu | Trạng thái |
|-----|---------|------------|
| Deploy SaaS code có release APIs + entitlement | Sau backup; cửa sổ bảo trì | **Chưa deploy** (theo yêu cầu hiện tại) |
| `content_ops` trên prod code | Có trong entitlement service | **BLOCKER** — prod tree **không** có `content_ops` |
| Default `content_ops=false` | Không bật hàng loạt khi deploy | **BLOCKER thiết kế** — staging code đưa `content_ops` vào `SEO_HELPER_CAPABILITIES` (bật theo plan trừ khi `usage_limits.limit_value=0`). Trước prod: đổi thành **opt-in** (không nằm base capabilities; chỉ canary site/org) |
| Canary 1–2 site nội bộ | Bật `content_ops` tường minh sau deploy | Chưa |

Plugin WP đã gate UI/menu theo `has_feature('content_ops')` — đúng hướng; phụ thuộc SaaS entitlement.

---

## 3. Plugin canary (sau khi prod sẵn sàng — chưa làm)

1. Website canary đang `1.0.4` → cài bridge **`1.0.5` thủ công một lần** (`docs/BRIDGE_1.0.5_INSTALL.md`).
2. Từ `1.0.5+` → cập nhật trực tiếp trong WP (Plugins / Bảng tin → Cập nhật / nút **Nâng cấp ngay**).
3. Đưa artifact **`v1.2.0-rc.2`** (hoặc bản canary sau UI) lên kênh beta prod — **không** ghi đè tag/artifact RC.
4. Test: update in-place, active, pairing/posts/media/metadata, ContentOps full flow, Rank Math/Yoast/Wordfence, negative gates.
5. Theo dõi 24–48h (fatal, 500, updater, job treo, backup/rollback, DB size).
6. Canary PASS → mới cân nhắc tag `v1.2.0` + rollout 5–10% → 25% → 50% → 100% — **cần xác nhận rõ ràng của bạn**.

---

## 4. Không làm trong checklist này

- Merge `master` / publish stable toàn bộ  
- Phase 3  
- Sửa Billing / Credit / API contract  
- Ghi đè tag `v1.2.0-rc.*` hoặc ZIP đã phát hành  
- Dùng credentials staging trên production  

---

## 5. Rollback (khi sau này được phép deploy)

1. Backup DB + giữ ZIP release trước migrate.  
2. Withdraw release lỗi; WP site giữ bản cũ nếu chưa cập nhật.  
3. Revert SaaS deploy; bảng release additive giữ nguyên.  
4. Tắt `content_ops` canary bằng entitlement (limit 0 / bỏ feature).
