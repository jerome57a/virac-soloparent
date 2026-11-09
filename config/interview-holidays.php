<?php
/**
 * Non-working holidays only; EDSA's special working day is deliberately excluded.
 * Update this calendar when new proclamations are issued. Additional local holidays
 * and office closures can be entered under System Settings > Appointments.
 * 2026: Proclamation 1006, plus Eid declarations (March 20 and May 27).
 * https://elibrary.judiciary.gov.ph/thebookshelf/showdocs/7/99678
 * https://pco.gov.ph/pcoo-archive/?viewed_date=2026-3-13
 * https://pco.gov.ph/news_releases/palace-declares-may-27-as-a-regular-holiday-for-eidl-adha-observance/
 * 2027: Proclamation 1427. Eid dates must be added after their official declaration.
 * https://elibrary.judiciary.gov.ph/thebookshelf/showdocs/7/102791
 */
return [
    2026 => [
        '01-01', '02-17', '03-20', '04-02', '04-03', '04-04', '04-09',
        '05-01', '05-27', '06-12', '08-21', '08-31', '11-01', '11-02',
        '11-30', '12-08', '12-24', '12-25', '12-30', '12-31',
    ],
    2027 => [
        '01-01', '02-06', '03-25', '03-26', '03-27', '04-09', '05-01',
        '06-12', '08-21', '08-30', '11-01', '11-02', '11-30', '12-08',
        '12-24', '12-25', '12-30', '12-31',
    ],
];
