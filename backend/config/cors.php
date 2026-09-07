<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],

    // الأصول المسموحة تُقرأ من CORS_ALLOWED_ORIGINS في .env (قائمة مفصولة
    // بفواصل — تُشذَّب المسافات حول كل مدخل وتُسقَط المداخل الفارغة، فالفاصلة
    // الزائدة أو المسافة الشاردة لا تنتجان أصلاً مكسوراً).
    // قرار محسوم: الافتراضي «مغلق» — غياب المتغيّر أو فراغه يعيد
    // ['http://localhost:8080'] (أصل التطوير المحلي) فقط، ولا يعود '*' بأي
    // حال: نسيان ضبطه في الإنتاج يجب أن يظهر عالياً كفشل CORS في الواجهة،
    // لا أن يُفتح الـAPI لكل الأصول بصمت.
    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))
    ))) ?: ['http://localhost:8080'],
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    // المصادقة بتوكن Bearer لا بالكوكيز — لا حاجة لبيانات اعتماد المتصفح،
    // وتركيبة ('*' + credentials:true) مخالفة لمواصفة CORS أصلاً.
    'supports_credentials' => false,
];
