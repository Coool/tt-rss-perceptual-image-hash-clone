CREATE OR REPLACE FUNCTION bit_count(bigint) RETURNS integer AS $$
	SELECT length(replace($1::bit(64)::text, '0', '')); $$
LANGUAGE SQL IMMUTABLE STRICT;
