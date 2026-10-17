<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login — Toko Golden</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root{--ink:#20382c;--green:#196447;--gold:#e4b83f;--muted:#68746d}
        *{letter-spacing:0}
        body{margin:0;min-height:100vh;min-height:100dvh;display:flex;flex-direction:column;background:#eef1ef;color:var(--ink);font-family:"Segoe UI",system-ui,sans-serif}
        .login-topbar{min-height:72px;display:flex;align-items:center;justify-content:space-between;gap:20px;padding:16px 5%;background:#fff;border-bottom:1px solid #dce3de}
        .brand{display:flex;align-items:center;gap:12px}.brand-symbol{color:#98720b;font-size:30px;line-height:1}.brand-name{font-size:23px;font-weight:750}.topbar-caption{font-size:13px;color:var(--muted)}
        .login-stage{position:relative;isolation:isolate;flex:1;display:flex;align-items:center;justify-content:center;padding:56px 5%;min-height:740px}
        .store-image{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;z-index:-2}
        .login-stage::before{content:"";position:absolute;inset:0;background:linear-gradient(90deg,rgba(255,255,255,.94),rgba(255,255,255,.76) 29%,rgba(255,255,255,0) 59%);z-index:-1}
        .login-layout{width:100%;max-width:1600px;display:grid;grid-template-columns:minmax(0,1fr) minmax(0,560px);align-items:center;gap:72px}
        .login-copy{align-self:start;padding-top:42px;max-width:640px}.login-copy .eyebrow{font-size:14px;color:#9d730e;font-weight:650;margin-bottom:18px}.login-copy h1{font-size:50px;line-height:1.18;font-weight:750;margin:0 0 22px;color:#073e30}.login-copy h1 span{color:#b88a22}.login-copy p{font-size:18px;line-height:1.6;color:#5c6670;max-width:450px}.copy-rule{width:72px;height:4px;background:#c69828;margin-top:28px}
        .login-card{width:100%;border:1px solid #e0e5e1;border-top:5px solid #d9a719;border-radius:8px;background:rgba(255,255,255,.98);box-shadow:0 16px 60px rgba(13,32,23,.2);padding:40px 44px}
        .login-header{margin-bottom:30px}.form-brand{display:flex;align-items:center;justify-content:center;gap:20px;margin:4px 0 38px}.form-brand>i{font-size:48px;line-height:1;color:#b58820}.form-brand strong{display:block;font-size:28px;font-weight:750;color:#073e30}.form-brand small{display:block;color:#78818b;font-size:13px;margin-top:2px}
        .login-header h2{font-size:28px;font-weight:700;margin:0 0 10px;color:#073e30}.login-header p{font-size:15px;color:var(--muted);margin:0;line-height:1.5}
        .topbar-context{display:inline-flex;align-items:center;gap:10px;margin-left:22px;padding-left:22px;border-left:1px solid #d4d9d5}.topbar-context i{color:#ac801a;font-size:19px}
        .form-label{font-size:13px;margin-bottom:8px}.login-field{position:relative;display:flex;align-items:center}.field-icon{position:absolute;left:15px;color:#7b887f;pointer-events:none;z-index:2}
        .login-field .form-control{height:54px;padding-left:48px;padding-right:14px;border:1px solid #d5dce0;border-radius:6px;background:#fff;font-size:15px;min-width:0}
        .login-field .password-input{padding-right:48px}.login-field .is-invalid{border-color:#bd4545;background-image:none}
        .login-field .form-control:focus{background:#fff;border-color:var(--green);box-shadow:0 0 0 3px rgba(25,100,71,.11)}
        #togglePassword{position:absolute;right:5px;width:38px;height:38px;display:grid;place-items:center;border:0;border-radius:4px;background:transparent;color:#647269}
        #togglePassword:hover{background:#eaf0ec;color:var(--green)}#togglePassword:focus-visible{outline:2px solid var(--green);outline-offset:1px}
        .remember-row{margin:20px 0 24px}.form-check{margin:0}.form-check-input{border-color:#b4c3b9}.form-check-input:checked{background-color:var(--green);border-color:var(--green)}.form-check-input:focus{border-color:var(--green);box-shadow:0 0 0 3px rgba(25,100,71,.11)}
        .btn-login{height:56px;display:flex;align-items:center;justify-content:center;gap:16px;background:#12533f;border:1px solid #12533f;color:#fff;font-size:17px;font-weight:650;border-radius:6px;box-shadow:0 4px 10px rgba(18,83,63,.13)}
        .btn-login:hover,.btn-login:active{background:#124e36!important;border-color:#124e36!important;color:#fff!important}.btn-login:focus-visible{outline:3px solid var(--gold);outline-offset:3px}
        .alert{display:flex;align-items:flex-start;gap:8px;font-size:13px;line-height:1.6;border-radius:6px;padding:11px 13px;margin-bottom:20px}.alert-danger{background:#fff2f1;border-color:#f2d4d0;color:#9a3232}
        @media(max-width:1199px){.login-layout{grid-template-columns:minmax(0,1fr) minmax(0,490px);gap:36px}.login-copy h1{font-size:40px}.login-card{padding:32px}.topbar-context{display:none}}
        @media(max-width:991px){.login-layout{display:flex;justify-content:center}.login-copy{display:none}.login-card{max-width:490px}.login-stage::before{background:rgba(240,244,241,.2)}}
        @media(max-width:575px){.login-topbar{padding:16px 20px;min-height:72px}.brand-name{font-size:21px}.topbar-caption{display:none}.login-stage{padding:28px 16px;min-height:620px}.login-card{padding:28px 24px}.login-header h2{font-size:24px}.form-brand{margin-bottom:28px;gap:14px}.form-brand strong{font-size:25px}.form-brand>i{font-size:42px}}
        @media(prefers-reduced-motion:reduce){*{transition:none!important}}
    </style>
</head>
<body>
<header class="login-topbar">
    <div class="brand"><i class="bi bi-shop brand-symbol" aria-hidden="true"></i><span class="brand-name">Toko Golden</span></div>
    <span class="topbar-caption">Sistem Manajemen Inventori<span class="topbar-context"><i class="bi bi-box-seam" aria-hidden="true"></i>Toko Grosir Golden</span></span>
</header>
<main class="login-stage">
<img class="store-image" src="{{ asset('login-warehouse.png') }}" alt="" fetchpriority="high">
<div class="login-layout">
<div class="login-copy">
    <div class="eyebrow">TOKO GROSIR GOLDEN</div>
    <h1>Stok Teratur.<br>Bisnis <span>Lebih Lancar.</span></h1>
    <p>Toko Grosir Golden<br>Kota Weda, Halmahera Tengah.</p>
    <div class="copy-rule" aria-hidden="true"></div>
</div>
<section class="login-card" aria-labelledby="loginTitle">
    <div class="login-header">
        <div class="form-brand"><i class="bi bi-shop" aria-hidden="true"></i><div><strong>Toko Golden</strong><small>TOKO GROSIR</small></div></div>
        <h2 id="loginTitle">Selamat datang kembali</h2>
        <p>Masuk ke akun Toko Golden untuk melanjutkan.</p>
    </div>
    <div class="login-body">

        @if($errors->any())
        <div class="alert alert-danger" role="alert">
            <i class="bi bi-exclamation-circle me-1"></i>
            <span @if(session('login_retry_until')) id="loginCooldown" data-retry-until="{{ session('login_retry_until') }}" @endif>{{ $errors->first() }}</span>
        </div>
        @endif

        @if(session('success'))
        <div class="alert alert-success py-2">{{ session('success') }}</div>
        @endif

        <form method="POST" action="{{ route('login') }}">
            @csrf
            <div class="mb-3">
                <label for="email" class="form-label fw-semibold">Email</label>
                <div class="login-field">
                    <i class="bi bi-envelope field-icon" aria-hidden="true"></i>
                    <input id="email" type="email" name="email" autocomplete="username" class="form-control @error('email') is-invalid @enderror"
                           value="{{ old('email') }}" placeholder="email@tokogolden.com" required autofocus>
                </div>
            </div>

            <div class="mb-4">
                <label for="password" class="form-label fw-semibold">Password</label>
                <div class="login-field">
                    <i class="bi bi-lock field-icon" aria-hidden="true"></i>
                    <input id="password" type="password" name="password" autocomplete="current-password" class="form-control password-input" placeholder="Masukkan password" required>
                    <button type="button" id="togglePassword" class="btn btn-outline-secondary" aria-label="Tampilkan password" aria-controls="password" aria-pressed="false" title="Tampilkan password"><i class="bi bi-eye" aria-hidden="true"></i></button>
                </div>
            </div>

            <div class="remember-row">
                <div class="form-check">
                    <input type="checkbox" name="remember" class="form-check-input" id="remember">
                    <label class="form-check-label small" for="remember">Ingat saya</label>
                </div>
            </div>

            <button type="submit" class="btn btn-login w-100">
                <span>Masuk</span><i class="bi bi-arrow-right" aria-hidden="true"></i>
            </button>
        </form>
    </div>
</section>
</div>
</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="{{ asset('js/login.js') }}"></script>
</body>
</html>
