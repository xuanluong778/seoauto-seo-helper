# Go / No-Go — Stable `v1.2.0`

**Ngày đánh giá:** 2026-07-23  
**RC baseline:** `v1.2.0-rc.3` @ `9d15add` (tag annotated, immutable; không ghi đè rc.1/rc.2)  
**UI simplify:** `3e6da59`  
**SaaS content_ops opt-in:** `e245fea` + canary allowlist `a88e63b`

## Kết luận hiện tại: **NO-GO** publish stable `v1.2.0`

Staging RC3 **PASS**. Production credentials + prod canary 24–48h **chưa sẵn**. Dừng trước publish stable toàn bộ.

---

## Staging RC3

| Hạng mục | Kết quả |
|----------|---------|
| CI draft build/sign/upload | **PASS** [run 30020371860](https://github.com/xuanluong778/seoauto-seo-helper/actions/runs/30020371860) |
| Publish beta | **PASS** [run 30020550742](https://github.com/xuanluong778/seoauto-seo-helper/actions/runs/30020550742) |
| Dual-site live update rc.2 → rc.3 | **PASS** (active, pairing/posts/media giữ nguyên) |
| ContentOps Preview→…→Rollback (2 site) | **PASS** |
| Ext E2E Rank Math + Wordfence | **PASS** |
| Negative checksum/sig/replay/downgrade/LOCKED/missing | **PASS** |
| Yoast (site2) | **PASS** (active) |
| UI simplified + ContentOps menu gate | **PASS** |

## Production blockers

| # | Blocker |
|---|---------|
| 1 | Prod thiếu R2 bucket/key, CI token, signing key (không dùng staging) |
| 2 | Chưa publish RC3 lên kênh beta **production** (cần mục 1) |
| 3 | Chưa có WP canary production đã xác nhận + theo dõi 24–48h |
| 4 | Chưa deploy SaaS opt-in lên **digiseo production** (chỉ staging đã làm) |
| 5 | Chưa có xác nhận rõ ràng của bạn để publish stable |

## Canary gate (SaaS)

- Default: `content_ops` **off**
- Bật: `UsageLimit` / `PLAN_FEATURES` **hoặc** `PLUGIN_CONTENT_OPS_CANARY_USER_IDS`
- Staging: canary user `1` (admin paired sites)
- Không dùng `WP_DEBUG`

## Khi nào GO

1. Tạo credential production riêng + smoke R2/API.  
2. Deploy SaaS `a88e63b` lên prod; set canary user ids 1–2 org.  
3. Publish RC3 beta **prod** → canary WP update + ContentOps + 24–48h.  
4. Bạn xác nhận → mới tag `v1.2.0` và rollout dần (5–10%…100%).
