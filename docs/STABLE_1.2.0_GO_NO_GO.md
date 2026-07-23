# Go / No-Go — Stable `v1.2.0`

**Ngày đánh giá:** 2026-07-23  
**Baseline RC:** `v1.2.0-rc.2` @ `a9efca7`  
**UI simplify:** `3e6da59` (branch `feature/seo-helper-phase2-backup-rollback`)  
**Phạm vi lần này:** review + checklist + retest staging; **không** deploy prod, **không** publish stable.

## Kết luận: **NO-GO** cho `v1.2.0` stable

Canary staging / RC logic **PASS**, nhưng production credentials + SaaS `content_ops` opt-in **chưa sẵn** → không được tag/publish stable.

---

## Điều kiện bắt buộc

| # | Điều kiện | Kết quả |
|---|-----------|---------|
| 1 | UI chính: kết nối, version, kiểm tra/nâng cấp cập nhật, thông báo | **PASS** (`3e6da59`) |
| 2 | ContentOps menu “Sửa SEO & Khôi phục” chỉ khi `content_ops` | **PASS** (WP gate) |
| 3 | Pairing / HMAC / updater verify / CI RC không bị rewrite | **PASS** (không đổi core) |
| 4 | Dual staging: plugin active `1.2.0-rc.2`, paired, update_check | **PASS** |
| 5 | Preview→Backup→Apply→Recheck→Rollback (2 site) | **PASS** |
| 6 | Ext E2E Rank Math + Wordfence + gates | **PASS** (site1) |
| 7 | Negative: checksum/sig/expiry/replay/downgrade/LOCKED/missing object | **PASS** |
| 8 | Prod R2 bucket/key + CI token + signing key riêng | **FAIL** (absent) |
| 9 | SaaS prod có `content_ops` + default false / canary opt-in | **FAIL** |
| 10 | Canary production 24–48h | **Chưa làm** (đúng yêu cầu dừng) |
| 11 | Xác nhận rõ ràng để publish stable | **Chưa có** |

## No-Go nếu (đang dính)

- Prod thiếu `R2_*` / `WP_PLUGIN_*`
- Deploy SaaS mà `content_ops` nằm base capabilities (bật hàng loạt)
- Chưa canary prod + theo dõi 24–48h
- Chưa có xác nhận của bạn để publish stable

## Khi nào chuyển GO

1. Tạo credential production riêng (không copy staging).  
2. Deploy SaaS với `content_ops` **opt-in**, mặc định tắt; bật 1–2 site canary.  
3. Canary update `rc.2` (hoặc bản đã chốt) + E2E + negative + 24–48h xanh.  
4. Bạn xác nhận rõ ràng → mới tag `v1.2.0` và rollout dần.
