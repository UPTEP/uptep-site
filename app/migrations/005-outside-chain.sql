BEGIN;
ALTER TABLE chaine_members DROP CONSTRAINT chaine_members_grade_check;
ALTER TABLE chaine_members ADD CONSTRAINT chaine_members_grade_check CHECK (grade IN ('Grenier','Passeur','Chasseur','A flooder','Ne pas toucher','Hors chaîne'));
COMMIT;
