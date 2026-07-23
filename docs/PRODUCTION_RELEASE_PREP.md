# Production readiness — Private Plugin Updater (inventory only)

**Không deploy / không restart production trong bước này.**

## Hiện trạng (inventory)

| Thành phần | Staging | Production (quan sát) |
|------------|---------|------------------------|
| SaaS code release APIs | Có (www-staging) | Prod env **chưa** có `WP_PLUGIN_*` / `R2_*` keys |
| Database release tables | Migrated staging | Cần dry-run trên bản sao / cửa sổ bảo trì |
| R2 bucket | `seoauto-plugin-staging` | Cần **bucket production riêng** (không dùng staging) |
| CI token / signing key | Staging riêng | Cần **token + signing key production riêng** |
| GitHub Environment | `plugin-release-stable` (required reviewers) | Dùng cho publish stable |
| GitHub Secrets | Đang trỏ **staging** API/R2 | Trước stable prod: trỏ (hoặc env riêng) production |

## Checklist trước deploy (cần xác nhận)

- [ ] Tạo Cloudflare R2 bucket production (ví dụ `seoauto-plugin-releases`) — **khác** staging
- [ ] Tạo R2 Access Key production — **không** dùng key staging / key đã lộ
- [ ] Set prod `env.local`: `WP_PLUGIN_STORAGE_BACKEND=r2`, `R2_*`, `WP_PLUGIN_CI_RELEASE_TOKEN`, `WP_PLUGIN_RELEASE_SIGNING_KEY`, `WP_PLUGIN_DOWNLOAD_TTL_SECONDS`
- [ ] Deploy SaaS commit có StorageAdapter + release APIs (branch `feature/seo-helper-release-cicd`, tối thiểu sau `3c75f7c`)
- [ ] Chạy `ensure_wordpress_plugin_tables` / migration trên prod (sau backup)
- [ ] Smoke API prod: create draft (canary) → HEAD object → withdraw — **không** publish stable vội
- [ ] Cập nhật GitHub Secrets (hoặc Environment secrets) cho production khi sẵn sàng phát stable
- [ ] Rotate R2 staging key đã lộ; xác nhận GitHub Secrets staging khớp

## Migration dry-run (staging-safe pattern)

```bash
# Trên staging (đã làm) — mẫu cho prod:
cd /path/to/seoauto && set -a && source env.local && set +a
.venv/bin/python -c "from app.migrations.ensure_wordpress_plugin_tables import ensure_wordpress_plugin_tables; print(ensure_wordpress_plugin_tables())"
```

Kỳ vọng: `wordpress_plugin_releases`, download tokens, update events có mặt; `missing=[]`.

## Rollback plan (khi deploy prod — chưa thực hiện)

1. Giữ release ZIP + DB dump trước migrate.
2. Nếu API lỗi: revert code deploy về commit trước; giữ bảng release (additive).
3. Nếu R2 sai: chuyển `WP_PLUGIN_STORAGE_BACKEND=local` tạm **chỉ** nếu đã có quy trình local fallback; hoặc trỏ lại bucket đúng.
4. Withdraw release lỗi qua CI API; không xóa object ngay.
5. WordPress: khách hàng vẫn chạy bản plugin cũ nếu chưa bấm cập nhật.

## Smoke API (prod — chỉ sau khi được phép)

1. `POST /api/wordpress-plugin/releases` (draft, verify_object_exists)
2. Presign/download token path
3. `POST .../publish?channel=beta` canary
4. `POST .../withdraw`
5. Không gọi publish `channel=stable` cho đến khi go/no-go PASS + approval
