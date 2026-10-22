<?php

declare(strict_types=1);

namespace App\Enums;

enum LotteryPrizeTier: string
{
    case FIRST_PRIZE = 'FIRST_PRIZE';                         // รางวัลที่ 1 (6 digits)
    case NEAR_FIRST_PRIZE = 'NEAR_FIRST_PRIZE';               // ข้างเคียงรางวัลที่ 1 (±1)
    case SECOND_PRIZE = 'SECOND_PRIZE';                       // รางวัลที่ 2 (6 digits)
    case THIRD_PRIZE = 'THIRD_PRIZE';                         // รางวัลที่ 3 (6 digits)
    case FOURTH_PRIZE = 'FOURTH_PRIZE';                       // รางวัลที่ 4 (6 digits)
    case FIFTH_PRIZE = 'FIFTH_PRIZE';                         // รางวัลที่ 5 (6 digits)
    case THREE_DIGIT_FRONT = 'THREE_DIGIT_FRONT';             // 3 ตัวหน้า (2 sets)
    case THREE_DIGIT_BACK = 'THREE_DIGIT_BACK';               // 3 ตัวท้าย (2 sets)
    case TWO_DIGIT_BOTTOM = 'TWO_DIGIT_BOTTOM';               // 2 ตัวท้าย (1 set)
    case THREE_DIGIT_DIRECT = 'THREE_DIGIT_DIRECT';           // 3 ตัวตรง (Last 3 of 1st prize)
    case THREE_DIGIT_TOAD = 'THREE_DIGIT_TOAD';               // 3 ตัวโต๊ด (Permutations of last 3 of 1st prize)
    case TWO_DIGIT_TOP = 'TWO_DIGIT_TOP';                     // 2 ตัวบน (Last 2 of 1st prize)
    case TWO_DIGIT_BOTTOM_VAR = 'TWO_DIGIT_BOTTOM_VAR';       // 2 ตัวล่าง (Matching 2-digit bottom)
    case RUNNING_TOP = 'RUNNING_TOP';                         // วิ่งบน (Single digit in last 3 of 1st prize)
    case RUNNING_BOTTOM = 'RUNNING_BOTTOM';                   // วิ่งล่าง (Single digit in 2-digit bottom)
}
