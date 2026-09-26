<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Memorization extends Model
{
    protected $fillable = [
        'student_id',
        'teacher_id',
        'date',
        'session_type',
        'surah_name',
        'juz',
        'hizb',
        'page_from',
        'page_to',
        'eighth',
        'quality',
        'notes',
    ];

    protected $casts = [
        'date' => 'date',
    ];

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function teacher()
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    public function getQualityLabelAttribute(): string
    {
        return match($this->quality) {
            'excellent' => 'ممتاز',
            'good'      => 'جيد',
            'average'   => 'مقبول',
            'weak'      => 'ضعيف',
            default     => $this->quality,
        };
    }

    public function getSessionTypeLabelAttribute(): string
    {
        return match($this->session_type) {
            'revision' => 'مراجعة',
            default    => 'حفظ جديد',
        };
    }
}
