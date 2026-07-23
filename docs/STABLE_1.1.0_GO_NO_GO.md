# Go / No-Go — Stable `v1.1.0`

## Điều kiện bắt buộc (tất cả phải PASS)

| # | Điều kiện | Trạng thái gần nhất |
|---|-----------|---------------------|
| 1 | Staging E2E Live Update PASS (`Cập nhật ngay` không mất dữ liệu) | PASS (beta.4 / rc.1) |
| 2 | Dual-site RC smoke (pairing, publish post, frontend, updater) | Site2 PASS; Site1 update_check từng FAIL khi API 502 — cần retest xanh |
| 3 | Chuỗi `1.0.4 → 1.0.5 → 1.1.0-rc.1` | Cần chạy lại với artifact bridge mới |
| 4 | R2 staging key đã rotate (không còn key lộ) | **FAIL — chưa rotate** |
| 5 | Production R2 + CI token + signing key riêng đã sẵn | **FAIL — chưa có trên prod env** |
| 6 | SaaS production đã migrate release tables + smoke API | **Chưa deploy** |
| 7 | Bridge `1.0.5` artifact + hướng dẫn phân phối | Đang hoàn thiện |
| 8 | Không có blocker Wordfence / pairing / data loss | Cần xác nhận sau retest |

## Quy trình tag stable (chỉ khi Go)

1. Đồng bộ version `1.1.0` (header + constant + readme Stable tag).
2. Merge **có kiểm soát** các thay đổi cần thiết vào `master` (không kèm `.env`, ZIP, log, secret).
3. `git tag -a v1.1.0` → push tag → Actions draft trên **production** R2/API.
4. Publish bằng workflow:
   - `channel=stable`
   - `staging_e2e_passed=true`
   - approval Environment `plugin-release-stable`
5. Không tự chạy bước 2–4 trong nhiệm vụ này.

## No-Go nếu

- Key R2 staging vẫn là key đã lộ
- Prod còn thiếu `R2_*` / `WP_PLUGIN_*`
- Dual-site update_check còn FAIL
- Chưa có kế hoạch phân phối bridge 1.0.5 cho khách 1.0.4
