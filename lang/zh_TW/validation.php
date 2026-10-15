<?php

return [
    'required' => ':attribute 為必填欄位。', 'email' => ':attribute 必須是有效的 Email。', 'unique' => ':attribute 已被使用。',
    'confirmed' => ':attribute 與確認欄位不一致。', 'integer' => ':attribute 必須是整數。', 'string' => ':attribute 必須是文字。',
    'uuid' => ':attribute 格式不正確，請重新開啟活動頁。', 'boolean' => ':attribute 必須為是或否。', 'date' => ':attribute 日期格式不正確。',
    'after' => ':attribute 必須晚於 :date。', 'before_or_equal' => ':attribute 必須早於或等於 :date。',
    'lowercase' => ':attribute 請使用小寫。', 'in' => ':attribute 選項不正確。', 'exists' => ':attribute 不存在。',
    'min' => ['numeric' => ':attribute 不得少於 :min。', 'string' => ':attribute 至少需要 :min 個字元。'],
    'max' => ['numeric' => ':attribute 不得超過 :max。', 'string' => ':attribute 不得超過 :max 個字元。'],
    'size' => ['string' => ':attribute 必須為 :size 個字元。'],
    'attributes' => ['email' => 'Email', 'password' => '密碼', 'name' => '姓名', 'quantity' => '數量', 'attendee_name' => '報名人姓名', 'phone' => '聯絡電話', 'contact_email' => '聯絡 Email', 'capacity' => '總名額', 'request_key' => '請求識別碼', 'token' => '票券代碼', 'title' => '活動名稱', 'description' => '活動介紹', 'location' => '地點', 'opens_at' => '報名開始', 'closes_at' => '報名截止', 'starts_at' => '活動開始', 'ends_at' => '活動結束', 'price' => '票價', 'per_user_limit' => '每人限額'],
];
