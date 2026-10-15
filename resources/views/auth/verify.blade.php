@extends('layout')
@section('content')<section class="panel auth"><h1>驗證你的 Email</h1><p>請點擊驗證郵件中的連結，完成後即可報名。</p>@if(config('mail.default')==='log' && app()->environment('local'))<div class="notice">目前使用本機郵件記錄模式；驗證連結寫入 storage/logs/laravel.log，未寄出真實郵件。</div>@endif<form method="post" action="{{ route('verification.send') }}">@csrf<button>重新寄送驗證信</button></form></section>@endsection
