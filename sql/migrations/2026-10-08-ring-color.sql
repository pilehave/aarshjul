-- Migration: baggrundsfarve for kategoriens ring (issue #7). Kan køres flere gange (MariaDB).
ALTER TABLE categories ADD COLUMN IF NOT EXISTS ring_color CHAR(7) NULL AFTER color;
