<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Agent portal copy — Thai
|--------------------------------------------------------------------------
| This file used to be a copy of the English source: 33 of its 45 keys held the
| English string, so an authenticated agent who chose Thai got an English portal
| inside a <html lang="th"> document. It now overrides every key it ships, with
| the dynamic state/status vocabulary (state_*, value_*) translated as well so a
| projected commission status is never echoed back in English.
*/

$english = require __DIR__.'/../en/agent.php';

return array_replace($english, [
    'title' => 'พอร์ทัลตัวแทน',
    'meta_description' => 'ประมาณการค่าคอมมิชชันและผู้แนะนำสำหรับตัวแทนที่เข้าสู่ระบบแล้ว',
    'home_aria' => 'หน้าหลักพอร์ทัลตัวแทน',
    'brand_subtitle' => 'งานปฏิบัติการตัวแทน',
    'primary_nav' => 'การนำทางพอร์ทัลตัวแทน',
    'nav_dashboard' => 'แดชบอร์ด',
    'nav_commissions' => 'ค่าคอมมิชชัน',
    'nav_settlements' => 'การชำระบัญชี',
    'nav_statement' => 'รายการเดินบัญชี',
    'nav_referrals' => 'ผู้แนะนำ',
    'authenticated' => 'ตัวแทนที่เข้าสู่ระบบแล้ว',
    'eyebrow' => 'งานปฏิบัติการตัวแทน',
    'dashboard_title' => 'แดชบอร์ดตัวแทน',
    'commissions_title' => 'ค่าคอมมิชชันของตัวแทน',
    'settlements_title' => 'การชำระบัญชีของตัวแทน',
    'statement_title' => 'รายการเดินบัญชีของตัวแทน',
    'referrals_title' => 'สรุปค่าคอมมิชชัน',
    'referral_detail_title' => 'รายละเอียดผู้แนะนำ',
    'description' => 'พอร์ทัลนี้แสดงเฉพาะประมาณการที่เซิร์ฟเวอร์คำนวณให้สำหรับตัวแทนที่เข้าสู่ระบบแล้ว',
    'no_private_data_note' => 'ยอดเงินส่วนตัวของผู้เล่น ประวัติการชำระเงิน เอกสาร KYC และบันทึกของผู้ประกอบการจะไม่แสดงที่นี่',
    'metadata' => 'ข้อมูลเมตาของตัวแทน',
    'agent_reference' => 'รหัสอ้างอิงตัวแทน',
    'status' => 'สถานะ',
    'currency' => 'สกุลเงิน',
    'referrals' => 'ผู้แนะนำ',
    'snapshot' => 'สรุปค่าคอมมิชชัน',
    'referred_players' => 'ผู้เล่นที่แนะนำ',
    'active_players' => 'ผู้เล่นที่ใช้งานอยู่',
    'turnover' => 'ยอดเดิมพันรวม',
    'commission_accrued' => 'ค่าคอมมิชชันที่เกิดขึ้น',
    'records' => 'รายการ',
    'records_count' => 'รายการ',
    'reference' => 'อ้างอิง',
    'type_or_basis' => 'ประเภทหรือเกณฑ์',
    'amount_or_status' => 'จำนวนเงินหรือสถานะ',
    'created' => 'สร้างเมื่อ',
    'no_records' => 'ไม่มีรายการสำหรับประมาณการนี้',
    'no_data' => 'ไม่มีข้อมูล',
    'not_configured' => 'ยังไม่ได้ตั้งค่า',
    'state_available' => 'พร้อมใช้งาน',
    'state_no_data' => 'ไม่มีข้อมูล',
    'unknown_value' => 'ไม่พร้อมใช้งาน',
    'value_active' => 'ใช้งานอยู่',
    'value_inactive' => 'ไม่ได้ใช้งาน',
    'value_suspended' => 'ระงับชั่วคราว',
    'value_terminated' => 'สิ้นสุดแล้ว',
    'value_banned' => 'ถูกห้าม',
    'value_pending_verification' => 'รอการยืนยัน',
    'value_accrued' => 'เกิดขึ้นแล้ว',
    'value_calculated' => 'คำนวณแล้ว',
    'value_payable' => 'ถึงกำหนดจ่าย',
    'value_paid' => 'จ่ายแล้ว',
    'value_reversed' => 'กลับรายการแล้ว',
    'value_cancelled' => 'ยกเลิกแล้ว',
]);
