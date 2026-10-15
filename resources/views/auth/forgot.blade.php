@extends('layout')
@section('content')<section class="panel auth"><h1>重設密碼</h1><form method="post" action="{{ route('password.email') }}">@csrf<label>註冊 Email<input name="email" type="email" required></label><div class="actions"><button>寄送重設連結</button></div></form></section>@endsection
