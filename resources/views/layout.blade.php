<!doctype html>
<html lang="zh-Hant"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="referrer" content="same-origin"><title>@yield('title','TicketHub 活動報名')</title><link rel="stylesheet" href="{{ asset('app.css') }}"></head>
<body><header><a class="brand" href="{{ route('home') }}">◈ TicketHub <small>活動・相遇・新體驗</small></a><nav><a href="{{ route('home') }}">探索活動</a>@auth<a href="{{ route('orders.index') }}">我的票券</a>@if(auth()->user()->is_admin)<a href="{{ route('admin.index') }}">管理後台</a>@endif<form method="post" action="{{ route('logout') }}">@csrf<button class="plain">登出</button></form>@else<a href="{{ route('login') }}">登入</a><a class="button" href="{{ route('register') }}">建立帳號</a>@endauth</nav></header>
<main>@if(session('status'))<div class="notice" role="status">{{ session('status') }}</div>@endif
@if($errors->any())<div class="error" role="alert"><strong>請確認以下內容</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
@yield('content')</main><footer>TicketHub · 所有時間均為台灣時間（UTC+8） · 金額為新台幣</footer><script src="{{ asset('app.js') }}" defer></script></body></html>
