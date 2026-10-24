<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Notification centre copy — Thai
|--------------------------------------------------------------------------
| The Thai file used to be an English copy: the centre rendered "Notification
| center", "No notifications are available." and an English status vocabulary
| inside a <html lang="th"> document. Every key the centre prints is now
| translated, including the event and lifecycle labels shown per row.
|
| array_replace over the English source keeps the key set in step automatically:
| a key added to English can never be missing here, it can only be untranslated.
*/

$english = require __DIR__.'/../en/notifications.php';

return array_replace($english, [
    'title' => 'ศูนย์การแจ้งเตือน',
    'meta_description' => 'รายการแจ้งเตือนเฉพาะของบัญชีที่เข้าสู่ระบบแล้ว',
    'home_aria' => 'หน้าหลักศูนย์การแจ้งเตือน',
    'brand_subtitle' => 'ศูนย์การแจ้งเตือน',
    'primary_nav' => 'การนำทางการแจ้งเตือน',
    'nav_dashboard' => 'แดชบอร์ด',
    'nav_notifications' => 'การแจ้งเตือน',
    'nav_support' => 'ฝ่ายช่วยเหลือ',
    'eyebrow' => 'การแจ้งเตือนของบัญชี',
    'description' => 'แสดงเฉพาะการแจ้งเตือนที่เป็นของบัญชีที่เข้าสู่ระบบแล้วเท่านั้น',
    'records' => 'การแจ้งเตือน',
    'type' => 'ประเภท',
    'subject' => 'เรื่อง',
    'status' => 'สถานะ',
    'created' => 'สร้างเมื่อ',
    'read' => 'อ่านแล้ว',
    'unread' => 'ยังไม่อ่าน',
    'no_records' => 'ไม่มีการแจ้งเตือน',
    'no_data' => 'ไม่มีข้อมูล',
    'state_available' => 'พร้อมใช้งาน',
    'state_no_data' => 'ไม่มีการแจ้งเตือน',
    'unknown_value' => 'ไม่พร้อมใช้งาน',
    'status_pending' => 'รอดำเนินการ',
    'status_queued' => 'อยู่ในคิว',
    'status_sent' => 'ส่งแล้ว',
    'status_delivered' => 'ส่งถึงแล้ว',
    'status_failed' => 'ล้มเหลว',
    'status_suppressed' => 'ระงับการส่ง',
    'status_expired' => 'หมดอายุ',
    'event_kyc_verified' => 'ยืนยันตัวตนผ่าน',
    'event_kyc_rejected' => 'ยืนยันตัวตนไม่ผ่าน',
    'event_deposit_confirmed' => 'ยืนยันการฝากเงินแล้ว',
    'event_deposit_failed' => 'การฝากเงินล้มเหลว',
    'event_withdrawal_approved' => 'อนุมัติการถอนเงินแล้ว',
    'event_withdrawal_paid' => 'จ่ายเงินถอนแล้ว',
    'event_withdrawal_rejected' => 'ปฏิเสธการถอนเงิน',
    'event_bet_placed' => 'วางเดิมพันแล้ว',
    'event_bet_won' => 'ถูกรางวัลจากการเดิมพัน',
    'event_prize_claim_update' => 'อัปเดตการเรียกรางวัล',
    'event_compliance_hold' => 'ระงับเพื่อตรวจสอบด้านข้อกำหนด',
    'event_compliance_released' => 'ปลดระงับด้านข้อกำหนดแล้ว',
    'event_self_exclusion_started' => 'เริ่มการยกเว้นตนเอง',
    'event_limit_changed' => 'เปลี่ยนวงเงินของบัญชี',
    'event_session_revoked' => 'เพิกถอนเซสชันแล้ว',
    'event_reality_check' => 'แจ้งเตือนตรวจสอบเวลาการเล่น',
    'event_manual' => 'ประกาศถึงบัญชี',
    'event_glo_result_available' => 'มีผลสลาก GLO แล้ว',
    'event_dealer_request_update' => 'อัปเดตคำขอตัวแทนขาย',
]);
