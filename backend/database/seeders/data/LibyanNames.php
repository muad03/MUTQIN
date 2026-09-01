<?php

namespace Database\Seeders\Data;

/**
 * مصادر البيانات الليبية للبذر — أسماء وعائلات ومدن وعناوين توليدية بالكامل.
 * كل اسم عربي يقابله نقحرته اللاتينية (لتوليد البريد بنفس نمط النظام:
 * {نقحرة الاسم}.{نقحرة العائلة}.{id}@domain). لا أسماء ولا أرقام حقيقية.
 */
final class LibyanNames
{
    /** أسماء ذكور: عربي => نقحرة لاتينية. */
    public const MALE = [
        'محمد' => 'mohamed',       'أحمد' => 'ahmed',         'عبدالسلام' => 'abdulsalam',
        'الصادق' => 'alsadiq',     'مفتاح' => 'muftah',       'سالم' => 'salem',
        'خالد' => 'khaled',        'فرج' => 'faraj',          'جمعة' => 'juma',
        'الهادي' => 'alhadi',      'عبدالباسط' => 'abdulbaset', 'بشير' => 'bashir',
        'إدريس' => 'idris',        'عبدالرزاق' => 'abdulrazaq', 'ميلود' => 'miloud',
        'الطاهر' => 'altaher',     'منصور' => 'mansour',      'عمران' => 'omran',
        'سليمان' => 'suleiman',    'رمضان' => 'ramadan',      'عياد' => 'ayad',
        'نوري' => 'nuri',          'صلاح' => 'salah',         'أسامة' => 'osama',
        'معاذ' => 'muadh',         'أنس' => 'anas',           'زكريا' => 'zakaria',
        'يوسف' => 'yusuf',         'إبراهيم' => 'ibrahim',    'عبدالله' => 'abdullah',
    ];

    /** أسماء إناث: عربي => نقحرة لاتينية. */
    public const FEMALE = [
        'فاطمة' => 'fatima',   'عائشة' => 'aisha',     'خديجة' => 'khadija',
        'مبروكة' => 'mabrouka', 'سالمة' => 'salma',     'نجاة' => 'najat',
        'هدى' => 'huda',       'أسماء' => 'asma',       'مريم' => 'mariam',
        'زينب' => 'zainab',    'رقية' => 'ruqaya',      'حليمة' => 'halima',
        'نوارة' => 'nawara',   'إيمان' => 'iman',       'سعاد' => 'suad',
        'أمينة' => 'amina',    'صفاء' => 'safa',        'خولة' => 'khawla',
        'بثينة' => 'buthaina', 'رحمة' => 'rahma',
    ];

    /** ألقاب/عائلات ليبية: عربي => نقحرة لاتينية. */
    public const FAMILIES = [
        'المغربي' => 'almaghrabi',   'الفيتوري' => 'alfitouri',  'الورفلي' => 'alwarfalli',
        'العبيدي' => 'alobeidi',     'المسماري' => 'almismari',  'بن غشير' => 'bingashir',
        'الزوي' => 'alzway',         'الترهوني' => 'altarhuni',  'الشريف' => 'alsharif',
        'الدرسي' => 'aldarsi',       'البرعصي' => 'albarassi',   'المجبري' => 'almajbari',
        'الكيلاني' => 'alkilani',    'الشلوي' => 'alshalwi',     'العواكلي' => 'alawakli',
        'السنوسي' => 'alsanusi',     'الفاخري' => 'alfakhri',    'الجازوي' => 'aljazwi',
        'القطعاني' => 'alqataani',
    ];

    /** مدن ليبية. */
    public const CITIES = [
        'بنغازي', 'طرابلس', 'مصراتة', 'البيضاء', 'درنة', 'طبرق', 'سبها',
        'أجدابيا', 'الزاوية', 'سرت', 'المرج', 'زليتن', 'الخمس', 'غريان',
    ];

    /** عناوين/أحياء بنغازي. */
    public const BENGHAZI_ADDRESSES = [
        'شارع الببسي', 'الكيش', 'الفويهات', 'سيدي حسين', 'بوعطني', 'القوارشة',
        'الهواري', 'السلماني', 'رأس عبيدة', 'سيدي خليفة', 'بنينا', 'الليثي', 'الصابري',
    ];

    /** نقحرة اسم مركّب (اسم أول + [أوسط] + عائلة) — تعتمد الخرائط أعلاه حصراً. */
    public static function latin(string ...$arabicParts): string
    {
        $all = self::MALE + self::FEMALE + self::FAMILIES;
        $out = [];
        foreach ($arabicParts as $p) {
            $p = trim($p);
            if ($p === '') {
                continue;
            }
            if (!isset($all[$p])) {
                throw new \RuntimeException("اسم بلا نقحرة في LibyanNames: «{$p}» — أضِفه للخريطة قبل الاستعمال");
            }
            $out[] = $all[$p];
        }

        return implode('.', $out);
    }
}
