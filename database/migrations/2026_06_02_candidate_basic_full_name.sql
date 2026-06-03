ALTER TABLE `Vati_Payfiller_Candidate_Basic_details`
    ADD COLUMN IF NOT EXISTS `full_name` varchar(255) DEFAULT NULL AFTER `last_name`;

DROP PROCEDURE IF EXISTS `SP_Vati_Payfiller_get_basic_details`;
DELIMITER $$
CREATE PROCEDURE `SP_Vati_Payfiller_get_basic_details`(
    IN p_application_id VARCHAR(50)
)
BEGIN
    SELECT
        first_name,
        middle_name,
        last_name,
        full_name,
        gender,
        dob,
        blood_group,
        father_name,
        mother_name,
        mobile,
        landline,
        email,
        marital_status,
        spouse_name,
        other_name,
        country,
        state,
        city_village,
        district,
        pincode,
        nationality,
        photo_path,
        resume_file,
        resume_original_name,
        created_at,
        updated_at
    FROM Vati_Payfiller_Candidate_Basic_details
    WHERE application_id = p_application_id;
END$$
DELIMITER ;
