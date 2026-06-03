<?php
require_once __DIR__ . '/../../config/env.php';

$token = trim((string)($_GET['token'] ?? ''));
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Capture Profile Photo</title>
    <style>
        :root{--blue:#0f74d1;--ink:#0f172a;--muted:#64748b;--line:#dbe7f3;--soft:#f6f9fc;}
        *{box-sizing:border-box}
        body{margin:0;font-family:Arial,sans-serif;background:linear-gradient(135deg,#eef7ff,#ffffff);color:var(--ink);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:18px}
        .mobile-photo-card{width:min(460px,100%);background:#fff;border:1px solid var(--line);border-radius:18px;box-shadow:0 20px 55px rgba(15,23,42,.12);padding:18px}
        .brand{display:flex;align-items:center;gap:10px;margin-bottom:14px}
        .brand-icon{width:38px;height:38px;border-radius:12px;background:#e8f3ff;color:var(--blue);display:grid;place-items:center;font-weight:900}
        h1{font-size:20px;margin:0}
        .subtitle{color:var(--muted);font-size:13px;line-height:1.45;margin:4px 0 16px}
        .capture-zone{border:1px dashed #b7d6f6;background:var(--soft);border-radius:16px;padding:14px;text-align:center}
        .preview{width:100%;max-height:380px;object-fit:contain;border-radius:14px;background:#e2e8f0;display:none;margin-bottom:12px}
        .file-input{position:absolute;left:-9999px}
        .btn-row{display:flex;gap:10px;flex-wrap:wrap;justify-content:center}
        button,label.button{border:0;border-radius:12px;padding:11px 14px;font-size:14px;font-weight:800;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;min-height:42px}
        .primary{background:linear-gradient(135deg,#0f7bd7,#075c9f);color:#fff}
        .secondary{background:#eef6ff;color:#075c9f;border:1px solid #c5ddf7}
        .ghost{background:#fff;color:#334155;border:1px solid #d6e2ef}
        .status{display:none;margin-top:12px;padding:10px 12px;border-radius:12px;font-size:13px;font-weight:700}
        .status.ok{display:block;background:#ecfdf3;color:#047857;border:1px solid #a7f3d0}
        .status.err{display:block;background:#fef2f2;color:#991b1b;border:1px solid #fecaca}
        .trust{font-size:12px;color:var(--muted);line-height:1.4;margin-top:14px;border-top:1px solid #e5edf6;padding-top:12px}
    </style>
</head>
<body>
<main class="mobile-photo-card">
    <div class="brand">
        <div class="brand-icon">ID</div>
        <div>
            <h1>Capture Profile Photo</h1>
            <p class="subtitle">Use your front camera for a clear verification photo.</p>
        </div>
    </div>

    <section class="capture-zone">
        <img id="photoPreview" class="preview" alt="Profile photo preview">
        <input id="photoInput" class="file-input" type="file" accept="image/*" capture="user">
        <div class="btn-row">
            <label class="button primary" for="photoInput" id="captureBtn">Open Camera</label>
            <button type="button" class="ghost" id="retakeBtn" style="display:none;">Retake</button>
            <button type="button" class="secondary" id="uploadBtn" disabled>Upload Photo</button>
        </div>
        <div id="statusBox" class="status"></div>
    </section>

    <p class="trust">Secure one-time upload session. The link expires automatically and can upload only one profile photo.</p>
</main>

<script>
(function () {
    const token = <?= json_encode($token) ?>;
    const input = document.getElementById('photoInput');
    const preview = document.getElementById('photoPreview');
    const uploadBtn = document.getElementById('uploadBtn');
    const retakeBtn = document.getElementById('retakeBtn');
    const statusBox = document.getElementById('statusBox');
    let compressedBlob = null;

    function showStatus(message, ok) {
        statusBox.textContent = message || '';
        statusBox.className = 'status ' + (ok ? 'ok' : 'err');
    }

    function compressImage(file) {
        return new Promise((resolve, reject) => {
            const reader = new FileReader();
            reader.onload = () => {
                const img = new Image();
                img.onload = () => {
                    const maxSide = 1200;
                    const scale = Math.min(1, maxSide / Math.max(img.width, img.height));
                    const canvas = document.createElement('canvas');
                    canvas.width = Math.max(1, Math.round(img.width * scale));
                    canvas.height = Math.max(1, Math.round(img.height * scale));
                    const ctx = canvas.getContext('2d');
                    ctx.fillStyle = '#fff';
                    ctx.fillRect(0, 0, canvas.width, canvas.height);
                    ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
                    canvas.toBlob(blob => {
                        if (!blob) reject(new Error('Unable to prepare image'));
                        else resolve(blob);
                    }, 'image/jpeg', 0.84);
                };
                img.onerror = () => reject(new Error('Invalid image'));
                img.src = reader.result;
            };
            reader.onerror = () => reject(new Error('Unable to read image'));
            reader.readAsDataURL(file);
        });
    }

    input.addEventListener('change', async () => {
        const file = input.files && input.files[0] ? input.files[0] : null;
        if (!file) return;
        if (!String(file.type || '').startsWith('image/')) {
            showStatus('Please select an image file.', false);
            return;
        }
        try {
            compressedBlob = await compressImage(file);
            preview.src = URL.createObjectURL(compressedBlob);
            preview.style.display = 'block';
            retakeBtn.style.display = '';
            uploadBtn.disabled = false;
            statusBox.className = 'status';
        } catch (e) {
            compressedBlob = null;
            uploadBtn.disabled = true;
            showStatus((e && e.message) ? e.message : 'Unable to prepare image.', false);
        }
    });

    retakeBtn.addEventListener('click', () => {
        input.value = '';
        compressedBlob = null;
        preview.style.display = 'none';
        uploadBtn.disabled = true;
        statusBox.className = 'status';
        input.click();
    });

    uploadBtn.addEventListener('click', async () => {
        if (!token || !compressedBlob) return;
        uploadBtn.disabled = true;
        showStatus('Uploading photo...', true);
        const form = new FormData();
        form.append('token', token);
        form.append('photo', compressedBlob, 'mobile-profile-photo.jpg');
        try {
            const res = await fetch(<?= json_encode(app_url('/api/candidate/mobile_photo_upload.php')) ?>, {
                method: 'POST',
                body: form
            });
            const data = await res.json().catch(() => null);
            if (!res.ok || !data || data.status !== 1) {
                throw new Error((data && data.message) ? data.message : 'Upload failed');
            }
            showStatus('Photo uploaded. You can return to your desktop screen.', true);
        } catch (e) {
            uploadBtn.disabled = false;
            showStatus((e && e.message) ? e.message : 'Upload failed.', false);
        }
    });

    if (!token) {
        showStatus('Invalid upload link.', false);
        uploadBtn.disabled = true;
    } else {
        setTimeout(() => {
            try { input.click(); } catch (e) {}
        }, 450);
    }
})();
</script>
</body>
</html>
