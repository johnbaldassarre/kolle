-- Sample camps (edit or delete from the admin panel)
INSERT INTO camps (name, description, location, start_date, end_date, schedule, min_birth_year, max_birth_year, capacity, price, is_active, sort_order) VALUES
('Elite Skills Camp – U15/U16', 'High-tempo on-ice skill development: edge work, puck skills, shooting and small-area competition.', 'Main Arena – Rink A', '2027-07-06', '2027-07-10', 'Mon–Fri · 9:00 AM – 12:00 PM', 2011, 2012, 30, 495.00, 1, 10),
('Junior / Prospect Camp', 'Pre-season training for Junior A, Major Junior and NCAA prospects. Game-pace drills, video and off-ice testing.', 'Main Arena – Rink A', '2027-08-09', '2027-08-13', 'Mon–Fri · 1:00 PM – 5:00 PM', 2005, 2010, 24, 650.00, 1, 20),
('Pro / College Summer Skate', 'Summer ice for pro, college and adult elite players. Goalies welcome.', 'Main Arena – Rink B', '2027-06-14', '2027-08-27', 'Tue & Thu · 7:00 AM – 8:30 AM (12 sessions)', NULL, 2008, 40, 375.00, 1, 30),
('Power Skating Clinic', 'Stride mechanics, crossovers, transitions and explosive starts.', 'Community Rink', '2027-07-19', '2027-07-21', '3 days · 5:00 PM – 6:30 PM', 2010, 2016, 25, 225.00, 1, 40),
('Shooting & Scoring Clinic', 'Release, deception and finishing around the net. Open to all ages 10+.', 'Community Rink', '2027-07-26', '2027-07-28', '3 days · 5:00 PM – 6:30 PM', NULL, 2016, 25, 225.00, 1, 50);
