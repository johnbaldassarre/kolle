-- Default levels of play. rank_value: higher = higher level. Keywords drive the auto-verifier.
INSERT INTO levels (name, category, rank_value, keywords, sort_order) VALUES
('NHL', 'Professional', 100, 'NHL,National Hockey League', 10),
('AHL', 'Professional', 92, 'AHL,American Hockey League', 20),
('European Pro (SHL, Liiga, KHL, DEL, NL, etc.)', 'Professional', 90, 'KHL,SHL,Liiga,DEL,National League,Czechia,Extraliga,HockeyAllsvenskan,Mestis,ICEHL,EIHL,VHL', 30),
('ECHL / Minor Pro (SPHL, FPHL)', 'Professional', 84, 'ECHL,SPHL,FPHL,LNAH', 40),
('NCAA Division I', 'College / University', 80, 'NCAA,NCAA D1,Hockey East,Big Ten,NCHC,ECAC,AHA,CCHA,Atlantic Hockey', 50),
('U SPORTS (Canadian University)', 'College / University', 74, 'U SPORTS,USports,OUA,AUS,Canada West', 60),
('NCAA Division III', 'College / University', 68, 'NCAA III,NCAA D3,NCAA-III,NESCAC,NEHC,MASCAC,WIAC,UCHC', 70),
('ACHA / ACAC / College Club', 'College / University', 55, 'ACHA,ACAC,BCIHL,CCHA ACHA', 80),
('CHL Major Junior (OHL, WHL, QMJHL)', 'Junior', 78, 'OHL,WHL,QMJHL,LHJMQ,CHL,Ontario Hockey League,Western Hockey League', 90),
('USHL', 'Junior', 76, 'USHL,United States Hockey League', 100),
('Junior A (BCHL, AJHL, OJHL, CCHL, NAHL, etc.)', 'Junior', 70, 'BCHL,AJHL,SJHL,MJHL,SIJHL,OJHL,NOJHL,CCHL,LHJAAAQ,MHL,NAHL,Junior A,CJHL', 110),
('Junior B / Tier III (GOJHL, KIJHL, PJHL, NA3HL, USPHL, etc.)', 'Junior', 60, 'GOJHL,KIJHL,PJHL,VIJHL,PIJHL,NA3HL,USPHL,Junior B,EHL,OJHL B,Junior C', 120),
('U18 AAA / Prep School', 'Youth', 58, 'U18 AAA,U18,18U AAA,18U,Prep,USHS-Prep,CSSHL,GTHL U18,ALLIANCE U18,OMHA U18,BCEHL U18,AEHL U18,SAAHL,MMHL', 130),
('U16 / U17 AAA', 'Youth', 50, 'U16 AAA,U16,16U AAA,16U,U17,17U,CSSHL U17,BCEHL U17,AEHL U17,GTHL U16', 140),
('U15 AAA', 'Youth', 44, 'U15 AAA,U15,15U AAA,15U,CSSHL U15,BCEHL U15,AEHL U15', 150),
('U13 / U14 AAA', 'Youth', 38, 'U14 AAA,U14,14U AAA,14U,U13 AAA,U13,13U', 160),
('AA (Youth)', 'Youth', 30, 'AA,U18 AA,U16 AA,U15 AA,U14 AA,U13 AA', 170),
('A / BB / Single A (Youth)', 'Youth', 22, 'BB,U18 A,U16 A,U15 A,U13 A,Single A', 180),
('U11 and younger', 'Youth', 15, 'U11,U10,U9,U8,11U,10U,9U,8U,Atom,Novice', 190),
('House League / Recreational', 'Recreational', 10, 'House League,HL,Rec,Recreational,Select', 200),
('Adult Recreational / Men''s League', 'Adult', 8, 'Adult Rec,Beer League,Men''s League,Senior,Old Timers', 210);

INSERT INTO settings (setting_key, setting_value) VALUES
('site_name', 'Ty Kolle Hockey Development'),
('contact_email', 'info@example.com'),
('currency_symbol', '$'),
('currency_code', 'CAD'),
('registration_open', '1'),
('waiver_text', 'I acknowledge that ice hockey is a contact sport with inherent risk of injury, and I release Ty Kolle Hockey Development, its staff and facilities from liability for injuries sustained during participation.');
