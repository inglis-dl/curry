DROP PROCEDURE IF EXISTS patch_ranked_word_word_total;
DELIMITER //
CREATE PROCEDURE patch_ranked_word_word_total()
  BEGIN

    SELECT "Adding new ranked_word_word_total view" AS ""; 

    SET @test = ( 
      SELECT COUNT(*)
      FROM information_schema.VIEWS
      WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = "ranked_word_word_total" );
    IF @test = 0 THEN
      SET @sql = CONCAT(
        "CREATE OR REPLACE VIEW ranked_word_word_total AS ",
        "SELECT w.id AS word_id, COUNT(terw.id) AS total, w.dictionary_id AS dictionary_id FROM word w ",
        "LEFT JOIN test_entry_ranked_word terw ON terw.word_id=w.id ",
        "LEFT JOIN test AS t1 ON t1.dictionary_id=w.dictionary_id ",
        "LEFT JOIN test AS t2 ON t2.intrusion_dictionary_id=w.dictionary_id ",
        "LEFT JOIN test AS t3 ON t3.variant_dictionary_id=w.dictionary_id ",
        "LEFT JOIN test AS t4 ON t4.mispelled_dictionary_id=w.dictionary_id ",
        "GROUP BY w.id " );
      PREPARE statement FROM @sql;
      EXECUTE statement;
      DEALLOCATE PREPARE statement;
     
    END IF; 
  END //
DELIMITER ;

CALL patch_ranked_word_word_total();
DROP PROCEDURE IF EXISTS patch_ranked_word_word_total;       
