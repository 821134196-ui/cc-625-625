<?php

/*
 * 特殊餐类型目录。
 * restricted = true 表示属于过敏 / 医疗 / 宗教需求，
 * 此类餐食在少餐时不允许用普通餐替代。
 */
return [
    'types' => [
        'CHML' => ['name' => '儿童餐',        'category' => 'normal',    'restricted' => false],
        'BBML' => ['name' => '婴儿餐',        'category' => 'normal',    'restricted' => false],
        'FPML' => ['name' => '水果餐',        'category' => 'normal',    'restricted' => false],
        'VGML' => ['name' => '素餐',          'category' => 'normal',    'restricted' => false],
        'NFML' => ['name' => '坚果过敏餐',    'category' => 'allergy',   'restricted' => true],
        'GFML' => ['name' => '无麸质餐',      'category' => 'allergy',   'restricted' => true],
        'SFML' => ['name' => '海鲜过敏餐',    'category' => 'allergy',   'restricted' => true],
        'DBML' => ['name' => '糖尿病餐',      'category' => 'medical',   'restricted' => true],
        'LSML' => ['name' => '低盐餐',        'category' => 'medical',   'restricted' => true],
        'LCML' => ['name' => '低卡路里餐',    'category' => 'medical',   'restricted' => true],
        'KSML' => ['name' => '犹太餐',        'category' => 'religious', 'restricted' => true],
        'MOML' => ['name' => '穆斯林餐',      'category' => 'religious', 'restricted' => true],
        'HNML' => ['name' => '印度餐',        'category' => 'religious', 'restricted' => true],
        'VJML' => ['name' => '耆那素餐',      'category' => 'religious', 'restricted' => true],
    ],

    // 少餐处理方式；substitute_regular 对 restricted 餐食不可用
    'shortage_resolutions' => [
        'passenger_declined'  => '旅客放弃/不需要',
        'deliver_after'       => '落地后补发补偿',
        'substitute_regular'  => '以普通餐替代（仅限非受限餐）',
        'other'               => '其他（备注说明）',
    ],
];
