# Production readiness — SEOAuto SEO Helper `v1.2.0` (checklist only)

**Không** dùng R2/key/token staging cho production. **Không** commit secret.

Baseline canary artifact: **`v1.2.0-rc.3`** (UI đơn giản). Tags `rc.1`/`rc.2` giữ nguyên.

---

## 1. Credential & storage production (riêng staging)

| Mục | Yêu cầu | Ghi chú |
|-----|---------|---------|
| R2 bucket production | Bucket mới (vd. `seoauto-plugin-releases`) | Không dùng `seoauto-plugin-staging` |
| R2 Access Key production | Key riêng | Không tái dùng key staging / key đã lộ |
| `WP_PLUGIN_CI_RELEASE_TOKEN` | Token CI production | |
| `WP_PLUGIN_RELEASE_SIGNING_KEY` | Signing key production | |
| `SEOAUTO_API_BASE` / `APP_BASE_URL` | `https://seoauto.vn` (prod) | Plugin canary trỏ prod |
| GitHub Environment secrets | `plugin-release-stable` trỏ **prod** khi publish stable | Staging secrets giữ riêng |

Tạo key ngoài Git; đưa vào prod `env.local` (chmod 600) + GitHub Environment — **không** commit.

---

## 2. SaaS `content_ops` (opt-in)

| Mục | Yêu cầu |
|-----|---------|
| Code | `content_ops` **không** nằm `SEO_HELPER_CAPABILITIES`; bật qua `UsageLimit` (`limit_value != 0`) hoặc `PLAN_FEATURES[slug].content_ops=True` |
| Default | **false** cho mọi plan |
| Canary | Chỉ set UsageLimit / plan flag cho 1–2 org nội bộ |
| WP_DEBUG | Không dùng làm feature gate |

---

## 3. Bridge `1.0.4` → updater

Site `1.0.4`: cài ZIP bridge `1.0.5` thủ công một lần (`docs/BRIDGE_1.0.5_INSTALL.md`), sau đó chỉ **Nâng cấp ngay**.

---

## 4. Canary → stable (cần xác nhận)

1. Deploy SaaS opt-in lên prod; bật `content_ops` canary.
2. Publish RC3 kênh beta **prod** (sau khi prod R2/token sẵn).
3. Canary WP: update trực tiếp + ContentOps E2E + theo dõi 24–48h.
4. PASS + xác nhận của bạn → tag `v1.2.0` → rollout 5–10% → 25% → 50% → 100%.
5. **Dừng trước publish stable 100%** nếu chưa có xác nhận rõ ràng.

---

## 5. Không làm

- Ghi đè tag/artifact `v1.2.0-rc.*`
- Phase 3 / sửa Billing / Credit / HMAC / updater core
- Copy credentials staging sang production
