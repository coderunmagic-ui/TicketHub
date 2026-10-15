@extends('layout')
@section('content')
<a href="{{ route('events.show',$type->event) }}">← 活動詳情</a>
<h1>先候位，再安心報名。</h1>
<div id="waiting-room" data-join="{{ route('waiting.join',$type) }}" data-status="{{ route('waiting.status') }}" data-leave="{{ route('waiting.leave') }}" data-type="{{ $type->id }}" data-csrf="{{ csrf_token() }}">
<div class="split"><section class="panel"><span class="eyebrow">WAITING ROOM</span><h2>{{ $type->event->title }}</h2><p>{{ $type->name }} · {{ $type->price_cents===0 ? '免費' : 'NT$ '.number_format($type->price_cents/100) }}</p><div class="notice" aria-live="polite"><strong id="queue-message">點擊下方按鈕開始候位。</strong><p id="queue-detail"></p></div><div class="actions"><button type="button" id="join-queue">加入候位</button><button type="button" class="secondary" id="leave-queue" hidden>離開候位</button></div><p class="muted">請保持頁面開啟，系統會自動更新。候位只提供送出報名的資格，不代表已保留票券。取得資格後，請在期限內送出表單。</p><p class="muted">一個帳號同時只能候位一種票券；若要改選，請先回到原候位頁離開。若送出後連線中斷，請先查閱<a href="{{ route('orders.index') }}">我的訂單</a>。</p></section>
<section class="panel"><h2>填寫報名資料</h2><form method="post" action="{{ route('orders.store',$type) }}" id="queued-booking">@csrf
<input type="hidden" name="queue_token" id="queue-token"><input type="hidden" name="request_key" id="request-key" value="{{ old('request_key',(string)\Illuminate\Support\Str::uuid()) }}">
<label>報名人姓名<input name="attendee_name" value="{{ old('attendee_name',auth()->user()->name) }}" required maxlength="100"></label>
<label>數量<input name="quantity" type="number" min="1" max="{{ $type->per_user_limit }}" value="{{ old('quantity',1) }}" required></label>
<label>聯絡電話<input name="phone" type="tel" value="{{ old('phone') }}" required maxlength="40"></label>
<label>聯絡 Email<input name="contact_email" type="email" value="{{ old('contact_email',auth()->user()->email) }}" required maxlength="254"></label>
<div class="actions"><button id="submit-booking" disabled>{{ $type->price_cents===0 ? '確認免費報名' : '保留名額，前往付款' }}</button></div></form></section></div></div>
<script src="{{ asset('waiting.js') }}" defer></script>
@endsection
