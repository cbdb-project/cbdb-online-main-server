<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ImportCbdbSchema extends Migration {
    /**
     * Run the migrations.
     *
     * This baseline migration consumes the legacy cbdb_schema.sql dump
     * and creates missing tables so that Laravel's migration history
     * matches the current database structure.
     */
    public function up(): void {
        $statements = $this->extractCreateStatements($this->schemaSql());

        if (empty($statements)) {
            return;
        }

        DB::transaction(function () use ($statements) {
            foreach ($statements as $table => $statement) {
                if (Schema::hasTable($table)) {
                    continue;
                }

                DB::statement($statement);
            }
        });
    }

    protected function schemaSql(): string {
        return <<<'SQL'
/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `ADDRESSES`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ADDRESSES` (
  `c_addr_id` int DEFAULT NULL,
  `c_addr_cbd` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `c_name_chn` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_admin_type` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_firstyear` smallint DEFAULT NULL,
  `c_lastyear` smallint DEFAULT NULL,
  `x_coord` double DEFAULT NULL,
  `y_coord` double DEFAULT NULL,
  `belongs1_ID` int DEFAULT NULL,
  `belongs1_Name` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `belongs2_ID` int DEFAULT NULL,
  `belongs2_Name` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `belongs3_ID` int DEFAULT NULL,
  `belongs3_Name` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `belongs4_ID` int DEFAULT NULL,
  `belongs4_Name` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `belongs5_ID` int DEFAULT NULL,
  `belongs5_Name` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  KEY `c_addr_id_ADDRESSES_index` (`c_addr_id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `ADDR_BELONGS_DATA`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ADDR_BELONGS_DATA` (
  `c_addr_id` int NOT NULL,
  `c_belongs_to` int NOT NULL,
  `c_firstyear` smallint NOT NULL,
  `c_lastyear` smallint NOT NULL,
  `c_source` int DEFAULT NULL,
  `c_pages` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_notes` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  PRIMARY KEY (`c_addr_id`,`c_belongs_to`,`c_firstyear`,`c_lastyear`),
  KEY `c_addr_id_ADDR_BELONGS_DATA_index` (`c_addr_id`) USING BTREE,
  KEY `c_belongs_to` (`c_belongs_to`) USING BTREE,
  KEY `c_source` (`c_source`),
  CONSTRAINT `ADDR_BELONGS_DATA_ibfk_1` FOREIGN KEY (`c_addr_id`) REFERENCES `ADDR_CODES` (`c_addr_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `ADDR_BELONGS_DATA_ibfk_2` FOREIGN KEY (`c_belongs_to`) REFERENCES `ADDR_CODES` (`c_addr_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `ADDR_BELONGS_DATA_ibfk_3` FOREIGN KEY (`c_source`) REFERENCES `TEXT_CODES` (`c_textid`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `ADDR_CODES`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ADDR_CODES` (
  `c_addr_id` int NOT NULL,
  `c_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `c_name_chn` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_firstyear` smallint DEFAULT NULL,
  `c_lastyear` smallint DEFAULT NULL,
  `c_admin_type` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `x_coord` double DEFAULT NULL,
  `y_coord` double DEFAULT NULL,
  `CHGIS_PT_ID` int DEFAULT NULL,
  `c_notes` longtext COLLATE utf8mb4_general_ci,
  `c_alt_names` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  PRIMARY KEY (`c_addr_id`) USING BTREE,
  KEY `c_addr_id_ADDR_CODES_index` (`c_addr_id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `ALTNAME_CODES`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ALTNAME_CODES` (
  `c_name_type_code` smallint NOT NULL,
  `c_name_type_desc` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_name_type_desc_chn` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  PRIMARY KEY (`c_name_type_code`) USING BTREE,
  KEY `c_name_type_code_ALTNAME_CODES_index` (`c_name_type_code`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `ALTNAME_DATA`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ALTNAME_DATA` (
  `c_personid` int NOT NULL,
  `c_alt_name` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_alt_name_chn` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `c_alt_name_type_code` smallint NOT NULL,
  `c_sequence` smallint DEFAULT '0',
  `c_source` int DEFAULT NULL,
  `c_pages` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_notes` longtext COLLATE utf8mb4_general_ci,
  `c_created_by` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_created_date` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_modified_by` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_modified_date` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  PRIMARY KEY (`c_alt_name_chn`,`c_alt_name_type_code`,`c_personid`) USING BTREE,
  KEY `c_personid_ALTNAME_DATA_index` (`c_personid`) USING BTREE,
  KEY `c_alt_name_type_code_ALTNAME_DATA_index` (`c_alt_name_type_code`) USING BTREE,
  KEY `c_source` (`c_source`),
  CONSTRAINT `ALTNAME_DATA_ibfk_1` FOREIGN KEY (`c_alt_name_type_code`) REFERENCES `ALTNAME_CODES` (`c_name_type_code`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `ALTNAME_DATA_ibfk_2` FOREIGN KEY (`c_personid`) REFERENCES `BIOG_MAIN` (`c_personid`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `ALTNAME_DATA_ibfk_3` FOREIGN KEY (`c_source`) REFERENCES `TEXT_CODES` (`c_textid`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `APPOINTMENT_CODES`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `APPOINTMENT_CODES` (
  `c_appt_code` smallint NOT NULL,
  `c_appt_desc_chn` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_appt_desc` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_appt_desc_chn_alt` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_appt_desc_alt` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_notes` longtext COLLATE utf8mb4_general_ci,
  PRIMARY KEY (`c_appt_code`) USING BTREE,
  KEY `c_appt_type_code_APPOINTMENT_TYPE_CODES_index` (`c_appt_code`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `APPOINTMENT_CODE_TYPE_REL`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `APPOINTMENT_CODE_TYPE_REL` (
  `c_appt_type_code` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `c_appt_code` smallint NOT NULL,
  PRIMARY KEY (`c_appt_code`,`c_appt_type_code`) USING BTREE,
  KEY `c_appt_type_code` (`c_appt_type_code`),
  CONSTRAINT `APPOINTMENT_CODE_TYPE_REL_ibfk_1` FOREIGN KEY (`c_appt_code`) REFERENCES `APPOINTMENT_CODES` (`c_appt_code`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `APPOINTMENT_CODE_TYPE_REL_ibfk_2` FOREIGN KEY (`c_appt_type_code`) REFERENCES `APPOINTMENT_TYPES` (`c_appt_type_code`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `APPOINTMENT_TYPES`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `APPOINTMENT_TYPES` (
  `c_appt_type_code` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `c_appt_type_desc` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_appt_type_desc_chn` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  PRIMARY KEY (`c_appt_type_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `ASSOC_CODES`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ASSOC_CODES` (
  `c_assoc_code` smallint NOT NULL,
  `c_assoc_pair` smallint DEFAULT NULL,
  `c_assoc_pair2` smallint DEFAULT NULL,
  `c_assoc_desc` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_assoc_desc_chn` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_assoc_role_type` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_sortorder` smallint DEFAULT NULL,
  `c_example` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  PRIMARY KEY (`c_assoc_code`),
  KEY `c_assoc_code_ASSOC_CODES_index` (`c_assoc_code`) USING BTREE,
  KEY `c_assoc_pair2` (`c_assoc_pair2`),
  CONSTRAINT `ASSOC_CODES_ibfk_1` FOREIGN KEY (`c_assoc_pair2`) REFERENCES `ASSOC_CODES` (`c_assoc_code`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `ASSOC_CODE_TYPE_REL`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ASSOC_CODE_TYPE_REL` (
  `c_assoc_code` smallint NOT NULL,
  `c_assoc_type_code` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  PRIMARY KEY (`c_assoc_code`,`c_assoc_type_code`) USING BTREE,
  KEY `c_assoc_code_ASSOC_CODE_TYPE_REL_index` (`c_assoc_code`) USING BTREE,
  KEY `c_assoc_type_id_ASSOC_CODE_TYPE_REL_index` (`c_assoc_type_code`(191)) USING BTREE,
  KEY `c_assoc_type_id` (`c_assoc_type_code`) USING BTREE,
  CONSTRAINT `ASSOC_CODE_TYPE_REL_ibfk_1` FOREIGN KEY (`c_assoc_code`) REFERENCES `ASSOC_CODES` (`c_assoc_code`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `ASSOC_CODE_TYPE_REL_ibfk_2` FOREIGN KEY (`c_assoc_type_code`) REFERENCES `ASSOC_TYPES` (`c_assoc_type_code`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `ASSOC_DATA`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ASSOC_DATA` (
  `c_assoc_code` smallint NOT NULL,
  `c_personid` int NOT NULL,
  `c_kin_code` smallint NOT NULL,
  `c_kin_id` int NOT NULL,
  `c_assoc_id` int NOT NULL,
  `c_assoc_kin_code` smallint NOT NULL,
  `c_assoc_kin_id` int NOT NULL,
  `c_tertiary_personid` int DEFAULT NULL,
  `c_tertiary_type_notes` longtext COLLATE utf8mb4_general_ci,
  `c_assoc_count` smallint NOT NULL DEFAULT '1',
  `c_sequence` int DEFAULT '0',
  `c_assoc_first_year` int NOT NULL DEFAULT '-9999',
  `c_assoc_last_year` int DEFAULT NULL,
  `c_source` int DEFAULT NULL,
  `c_pages` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_notes` longtext COLLATE utf8mb4_general_ci,
  `c_assoc_fy_nh_code` smallint DEFAULT NULL,
  `c_assoc_fy_nh_year` smallint DEFAULT NULL,
  `c_assoc_fy_range` smallint DEFAULT NULL,
  `c_assoc_ly_nh_code` smallint DEFAULT NULL,
  `c_assoc_ly_nh_year` smallint DEFAULT NULL,
  `c_assoc_ly_range` smallint DEFAULT NULL,
  `c_addr_id` int DEFAULT NULL,
  `c_litgenre_code` int DEFAULT NULL,
  `c_occasion_code` int DEFAULT NULL,
  `c_topic_code` int DEFAULT NULL,
  `c_inst_code` int DEFAULT '0',
  `c_inst_name_code` smallint DEFAULT '0',
  `c_text_title` varchar(255) COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `c_assoc_claimer_id` int DEFAULT NULL,
  `c_assoc_fy_intercalary` smallint DEFAULT NULL,
  `c_assoc_fy_month` smallint DEFAULT NULL,
  `c_assoc_fy_day` smallint DEFAULT NULL,
  `c_assoc_fy_day_gz` smallint DEFAULT NULL,
  `c_assoc_ly_intercalary` smallint DEFAULT NULL,
  `c_assoc_ly_month` smallint DEFAULT NULL,
  `c_assoc_ly_day` smallint DEFAULT NULL,
  `c_assoc_ly_day_gz` smallint DEFAULT NULL,
  `c_created_by` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_created_date` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_modified_by` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_modified_date` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  PRIMARY KEY (`c_assoc_code`,`c_assoc_id`,`c_assoc_kin_code`,`c_assoc_kin_id`,`c_kin_code`,`c_kin_id`,`c_personid`,`c_text_title`,`c_assoc_first_year`) USING BTREE,
  KEY `c_assoc_code_ASSOC_DATA_index` (`c_assoc_code`) USING BTREE,
  KEY `c_personid_ASSOC_DATA_index` (`c_personid`) USING BTREE,
  KEY `c_kin_code_ASSOC_DATA_index` (`c_kin_code`) USING BTREE,
  KEY `c_kin_id_ASSOC_DATA_index` (`c_kin_id`) USING BTREE,
  KEY `c_assoc_id_ASSOC_DATA_index` (`c_assoc_id`) USING BTREE,
  KEY `c_assoc_kin_code_ASSOC_DATA_index` (`c_assoc_kin_code`) USING BTREE,
  KEY `c_assoc_kin_id_ASSOC_DATA_index` (`c_assoc_kin_id`) USING BTREE,
  KEY `c_tertiary_personid_ASSOC_DATA_index` (`c_tertiary_personid`) USING BTREE,
  KEY `c_assoc_nh_code_ASSOC_DATA_index` (`c_assoc_fy_nh_code`) USING BTREE,
  KEY `c_addr_id_ASSOC_DATA_index` (`c_addr_id`) USING BTREE,
  KEY `c_litgenre_code_ASSOC_DATA_index` (`c_litgenre_code`) USING BTREE,
  KEY `c_occasion_code_ASSOC_DATA_index` (`c_occasion_code`) USING BTREE,
  KEY `c_topic_code_ASSOC_DATA_index` (`c_topic_code`) USING BTREE,
  KEY `c_inst_code_ASSOC_DATA_index` (`c_inst_code`) USING BTREE,
  KEY `c_inst_name_code_ASSOC_DATA_index` (`c_inst_name_code`) USING BTREE,
  KEY `c_assoc_claimer_id_ASSOC_DATA_index` (`c_assoc_claimer_id`) USING BTREE,
  KEY `c_assoc_day_gz` (`c_assoc_fy_day_gz`),
  KEY `c_assoc_range` (`c_assoc_fy_range`),
  KEY `c_source` (`c_source`),
  CONSTRAINT `ASSOC_DATA_ibfk_1` FOREIGN KEY (`c_addr_id`) REFERENCES `ADDR_CODES` (`c_addr_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `ASSOC_DATA_ibfk_10` FOREIGN KEY (`c_tertiary_personid`) REFERENCES `BIOG_MAIN` (`c_personid`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `ASSOC_DATA_ibfk_11` FOREIGN KEY (`c_topic_code`) REFERENCES `SCHOLARLYTOPIC_CODES` (`c_topic_code`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `ASSOC_DATA_ibfk_12` FOREIGN KEY (`c_assoc_claimer_id`) REFERENCES `BIOG_MAIN` (`c_personid`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `ASSOC_DATA_ibfk_13` FOREIGN KEY (`c_assoc_code`) REFERENCES `ASSOC_CODES` (`c_assoc_code`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `ASSOC_DATA_ibfk_14` FOREIGN KEY (`c_assoc_fy_day_gz`) REFERENCES `GANZHI_CODES` (`c_ganzhi_code`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `ASSOC_DATA_ibfk_15` FOREIGN KEY (`c_assoc_id`) REFERENCES `BIOG_MAIN` (`c_personid`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `ASSOC_DATA_ibfk_16` FOREIGN KEY (`c_assoc_kin_code`) REFERENCES `KINSHIP_CODES` (`c_kincode`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `ASSOC_DATA_ibfk_17` FOREIGN KEY (`c_assoc_kin_id`) REFERENCES `BIOG_MAIN` (`c_personid`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `ASSOC_DATA_ibfk_18` FOREIGN KEY (`c_assoc_fy_nh_code`) REFERENCES `NIAN_HAO` (`c_nianhao_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `ASSOC_DATA_ibfk_19` FOREIGN KEY (`c_assoc_fy_range`) REFERENCES `YEAR_RANGE_CODES` (`c_range_code`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `ASSOC_DATA_ibfk_2` FOREIGN KEY (`c_inst_name_code`) REFERENCES `SOCIAL_INSTITUTION_NAME_CODES` (`c_inst_name_code`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `ASSOC_DATA_ibfk_3` FOREIGN KEY (`c_inst_code`) REFERENCES `SOCIAL_INSTITUTION_CODES` (`c_inst_code`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `ASSOC_DATA_ibfk_4` FOREIGN KEY (`c_kin_code`) REFERENCES `KINSHIP_CODES` (`c_kincode`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `ASSOC_DATA_ibfk_5` FOREIGN KEY (`c_kin_id`) REFERENCES `BIOG_MAIN` (`c_personid`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `ASSOC_DATA_ibfk_6` FOREIGN KEY (`c_litgenre_code`) REFERENCES `LITERARYGENRE_CODES` (`c_lit_genre_code`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `ASSOC_DATA_ibfk_7` FOREIGN KEY (`c_occasion_code`) REFERENCES `OCCASION_CODES` (`c_occasion_code`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `ASSOC_DATA_ibfk_8` FOREIGN KEY (`c_personid`) REFERENCES `BIOG_MAIN` (`c_personid`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `ASSOC_DATA_ibfk_9` FOREIGN KEY (`c_source`) REFERENCES `TEXT_CODES` (`c_textid`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `ASSOC_TYPES`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ASSOC_TYPES` (
  `c_assoc_type_code` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `c_assoc_type_desc` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_assoc_type_desc_chn` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_assoc_type_parent_id` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_assoc_type_level` smallint DEFAULT NULL,
  `c_assoc_type_sortorder` smallint DEFAULT NULL,
  `c_assoc_type_short_desc` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  PRIMARY KEY (`c_assoc_type_code`) USING BTREE,
  KEY `c_assoc_type_id_ASSOC_TYPES_index` (`c_assoc_type_code`(191)) USING BTREE,
  KEY `c_assoc_type_parent_id_ASSOC_TYPES_index` (`c_assoc_type_parent_id`(191)) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `ASSUME_OFFICE_CODES`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ASSUME_OFFICE_CODES` (
  `c_assume_office_code` smallint NOT NULL,
  `c_assume_office_desc_chn` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_assume_office_desc` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  PRIMARY KEY (`c_assume_office_code`),
  KEY `c_assume_office_code_ASSUME_OFFICE_CODES_index` (`c_assume_office_code`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `BIOG_ADDR_CODES`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `BIOG_ADDR_CODES` (
  `c_addr_type` smallint NOT NULL,
  `c_addr_desc` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_addr_desc_chn` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_addr_note` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_index_addr_rank` int DEFAULT NULL,
  `c_index_addr_default_rank` int DEFAULT NULL,
  PRIMARY KEY (`c_addr_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `BIOG_ADDR_DATA`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `BIOG_ADDR_DATA` (
  `c_personid` int NOT NULL,
  `c_addr_id` int NOT NULL DEFAULT '0',
  `c_addr_type` smallint NOT NULL,
  `c_sequence` int NOT NULL,
  `c_firstyear` int DEFAULT NULL,
  `c_lastyear` int DEFAULT NULL,
  `c_source` int DEFAULT NULL,
  `c_pages` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_notes` longtext COLLATE utf8mb4_general_ci,
  `c_fy_nh_code` smallint DEFAULT NULL,
  `c_ly_nh_code` smallint DEFAULT NULL,
  `c_fy_nh_year` smallint DEFAULT NULL,
  `c_ly_nh_year` smallint DEFAULT NULL,
  `c_fy_range` smallint DEFAULT NULL,
  `c_ly_range` smallint DEFAULT NULL,
  `c_natal` int DEFAULT NULL,
  `c_fy_intercalary` smallint DEFAULT NULL,
  `c_ly_intercalary` smallint DEFAULT NULL,
  `c_fy_month` smallint DEFAULT NULL,
  `c_ly_month` smallint DEFAULT NULL,
  `c_fy_day` smallint DEFAULT NULL,
  `c_ly_day` smallint DEFAULT NULL,
  `c_fy_day_gz` smallint DEFAULT NULL,
  `c_ly_day_gz` smallint DEFAULT NULL,
  `c_created_by` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_created_date` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_modified_by` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_modified_date` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_delete` smallint DEFAULT NULL,
  PRIMARY KEY (`c_personid`,`c_addr_id`,`c_addr_type`,`c_sequence`),
  KEY `c_personid_BIOG_ADDR_DATA_index` (`c_personid`) USING BTREE,
  KEY `c_addr_id_BIOG_ADDR_DATA_index` (`c_addr_id`) USING BTREE,
  KEY `c_fy_nh_code_BIOG_ADDR_DATA_index` (`c_fy_nh_code`) USING BTREE,
  KEY `c_ly_nh_code_BIOG_ADDR_DATA_index` (`c_ly_nh_code`) USING BTREE,
  KEY `c_addr_type` (`c_addr_type`),
  KEY `c_fy_day_gz` (`c_fy_day_gz`),
  KEY `c_fy_range` (`c_fy_range`),
  KEY `c_ly_day_gz` (`c_ly_day_gz`),
  KEY `c_ly_range` (`c_ly_range`),
  KEY `c_source` (`c_source`),
  CONSTRAINT `BIOG_ADDR_DATA_ibfk_1` FOREIGN KEY (`c_addr_id`) REFERENCES `ADDR_CODES` (`c_addr_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `BIOG_ADDR_DATA_ibfk_10` FOREIGN KEY (`c_source`) REFERENCES `TEXT_CODES` (`c_textid`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `BIOG_ADDR_DATA_ibfk_2` FOREIGN KEY (`c_addr_type`) REFERENCES `BIOG_ADDR_CODES` (`c_addr_type`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `BIOG_ADDR_DATA_ibfk_3` FOREIGN KEY (`c_fy_day_gz`) REFERENCES `GANZHI_CODES` (`c_ganzhi_code`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `BIOG_ADDR_DATA_ibfk_4` FOREIGN KEY (`c_fy_nh_code`) REFERENCES `NIAN_HAO` (`c_nianhao_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `BIOG_ADDR_DATA_ibfk_5` FOREIGN KEY (`c_fy_range`) REFERENCES `YEAR_RANGE_CODES` (`c_range_code`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `BIOG_ADDR_DATA_ibfk_6` FOREIGN KEY (`c_ly_day_gz`) REFERENCES `GANZHI_CODES` (`c_ganzhi_code`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `BIOG_ADDR_DATA_ibfk_7` FOREIGN KEY (`c_ly_nh_code`) REFERENCES `NIAN_HAO` (`c_nianhao_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `BIOG_ADDR_DATA_ibfk_8` FOREIGN KEY (`c_ly_range`) REFERENCES `YEAR_RANGE_CODES` (`c_range_code`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `BIOG_ADDR_DATA_ibfk_9` FOREIGN KEY (`c_personid`) REFERENCES `BIOG_MAIN` (`c_personid`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `BIOG_INST_CODES`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `BIOG_INST_CODES` (
  `c_bi_role_code` smallint NOT NULL,
  `c_bi_role_desc` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_bi_role_chn` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_notes` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  PRIMARY KEY (`c_bi_role_code`) USING BTREE,
  KEY `c_bi_role_code_BIOG_INST_CODES_index` (`c_bi_role_code`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `BIOG_INST_DATA`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `BIOG_INST_DATA` (
  `c_personid` int NOT NULL,
  `c_inst_name_code` smallint NOT NULL,
  `c_inst_code` int NOT NULL,
  `c_bi_role_code` smallint NOT NULL,
  `c_bi_begin_year` smallint DEFAULT NULL,
  `c_bi_by_nh_code` smallint DEFAULT NULL,
  `c_bi_by_nh_year` smallint DEFAULT NULL,
  `c_bi_by_range` smallint DEFAULT NULL,
  `c_bi_end_year` smallint DEFAULT NULL,
  `c_bi_ey_nh_code` smallint DEFAULT NULL,
  `c_bi_ey_nh_year` smallint DEFAULT NULL,
  `c_bi_ey_range` smallint DEFAULT NULL,
  `c_source` int DEFAULT NULL,
  `c_pages` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_notes` longtext COLLATE utf8mb4_general_ci,
  `c_created_by` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_created_date` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_modified_by` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_modified_date` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `tts_sysno` int DEFAULT NULL,
  PRIMARY KEY (`c_bi_role_code`,`c_inst_code`,`c_inst_name_code`,`c_personid`) USING BTREE,
  KEY `c_personid_BIOG_INST_DATA_index` (`c_personid`) USING BTREE,
  KEY `c_inst_name_code_BIOG_INST_DATA_index` (`c_inst_name_code`) USING BTREE,
  KEY `c_inst_code_BIOG_INST_DATA_index` (`c_inst_code`) USING BTREE,
  KEY `c_bi_role_code_BIOG_INST_DATA_index` (`c_bi_role_code`) USING BTREE,
  KEY `c_bi_by_nh_code_BIOG_INST_DATA_index` (`c_bi_by_nh_code`) USING BTREE,
  KEY `c_bi_ey_nh_code_BIOG_INST_DATA_index` (`c_bi_ey_nh_code`) USING BTREE,
  KEY `c_bi_by_range` (`c_bi_by_range`),
  KEY `c_bi_ey_range` (`c_bi_ey_range`),
  KEY `c_source` (`c_source`),
  CONSTRAINT `BIOG_INST_DATA_ibfk_1` FOREIGN KEY (`c_bi_by_nh_code`) REFERENCES `NIAN_HAO` (`c_nianhao_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `BIOG_INST_DATA_ibfk_2` FOREIGN KEY (`c_bi_by_range`) REFERENCES `YEAR_RANGE_CODES` (`c_range_code`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `BIOG_INST_DATA_ibfk_3` FOREIGN KEY (`c_bi_ey_nh_code`) REFERENCES `NIAN_HAO` (`c_nianhao_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `BIOG_INST_DATA_ibfk_4` FOREIGN KEY (`c_bi_ey_range`) REFERENCES `YEAR_RANGE_CODES` (`c_range_code`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `BIOG_INST_DATA_ibfk_5` FOREIGN KEY (`c_bi_role_code`) REFERENCES `BIOG_INST_CODES` (`c_bi_role_code`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `BIOG_INST_DATA_ibfk_6` FOREIGN KEY (`c_inst_name_code`) REFERENCES `SOCIAL_INSTITUTION_NAME_CODES` (`c_inst_name_code`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `BIOG_INST_DATA_ibfk_7` FOREIGN KEY (`c_inst_code`) REFERENCES `SOCIAL_INSTITUTION_CODES` (`c_inst_code`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `BIOG_INST_DATA_ibfk_8` FOREIGN KEY (`c_personid`) REFERENCES `BIOG_MAIN` (`c_personid`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `BIOG_INST_DATA_ibfk_9` FOREIGN KEY (`c_source`) REFERENCES `TEXT_CODES` (`c_textid`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `BIOG_MAIN`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `BIOG_MAIN` (
  `c_personid` int NOT NULL,
  `c_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `c_name_chn` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_index_year` int DEFAULT NULL,
  `c_index_year_type_code` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_index_year_source_id` int DEFAULT NULL,
  `c_female` smallint DEFAULT NULL,
  `c_index_addr_id` int DEFAULT '0',
  `c_index_addr_type_code` int DEFAULT NULL,
  `c_ethnicity_code` smallint DEFAULT NULL,
  `c_household_status_code` smallint DEFAULT NULL,
  `c_tribe` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_birthyear` smallint DEFAULT NULL,
  `c_by_nh_code` smallint DEFAULT NULL,
  `c_by_nh_year` smallint DEFAULT NULL,
  `c_by_range` smallint DEFAULT NULL,
  `c_deathyear` smallint DEFAULT NULL,
  `c_dy_nh_code` smallint DEFAULT NULL,
  `c_dy_nh_year` smallint DEFAULT NULL,
  `c_dy_range` smallint DEFAULT NULL,
  `c_death_age` smallint DEFAULT NULL,
  `c_death_age_range` smallint DEFAULT NULL,
  `c_fl_earliest_year` smallint DEFAULT NULL,
  `c_fl_ey_nh_code` smallint DEFAULT NULL,
  `c_fl_ey_nh_year` smallint DEFAULT NULL,
  `c_fl_ey_notes` longtext COLLATE utf8mb4_general_ci,
  `c_fl_latest_year` smallint DEFAULT NULL,
  `c_fl_ly_nh_code` smallint DEFAULT NULL,
  `c_fl_ly_nh_year` smallint DEFAULT NULL,
  `c_fl_ly_notes` longtext COLLATE utf8mb4_general_ci,
  `c_surname` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_surname_chn` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_mingzi` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_mingzi_chn` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_dy` smallint DEFAULT NULL,
  `c_choronym_code` smallint DEFAULT NULL,
  `c_notes` longtext COLLATE utf8mb4_general_ci,
  `c_by_intercalary` smallint DEFAULT NULL,
  `c_dy_intercalary` smallint DEFAULT NULL,
  `c_by_month` smallint DEFAULT NULL,
  `c_dy_month` smallint DEFAULT NULL,
  `c_by_day` smallint DEFAULT NULL,
  `c_dy_day` smallint DEFAULT NULL,
  `c_by_day_gz` smallint DEFAULT NULL,
  `c_dy_day_gz` smallint DEFAULT NULL,
  `c_surname_proper` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_mingzi_proper` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_name_proper` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_surname_rm` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_mingzi_rm` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_name_rm` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_created_by` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_created_date` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_modified_by` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_modified_date` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_self_bio` smallint DEFAULT NULL,
  PRIMARY KEY (`c_personid`) USING BTREE,
  KEY `c_personid_BIOG_MAIN_index` (`c_personid`) USING BTREE,
  KEY `c_ethnicity_code_BIOG_MAIN_index` (`c_ethnicity_code`) USING BTREE,
  KEY `c_household_status_code_BIOG_MAIN_index` (`c_household_status_code`) USING BTREE,
  KEY `c_by_nh_code_BIOG_MAIN_index` (`c_by_nh_code`) USING BTREE,
  KEY `c_dy_nh_code_BIOG_MAIN_index` (`c_dy_nh_code`) USING BTREE,
  KEY `c_fl_ey_nh_code_BIOG_MAIN_index` (`c_fl_ey_nh_code`) USING BTREE,
  KEY `c_fl_ly_nh_code_BIOG_MAIN_index` (`c_fl_ly_nh_code`) USING BTREE,
  KEY `c_choronym_code_BIOG_MAIN_index` (`c_choronym_code`) USING BTREE,
  KEY `c_by_day_gz` (`c_by_day_gz`),
  KEY `c_by_range` (`c_by_range`),
  KEY `c_death_age_range` (`c_death_age_range`),
  KEY `c_dy` (`c_dy`),
  KEY `c_dy_day_gz` (`c_dy_day_gz`),
  KEY `c_dy_range` (`c_dy_range`),
  CONSTRAINT `BIOG_MAIN_ibfk_1` FOREIGN KEY (`c_by_day_gz`) REFERENCES `GANZHI_CODES` (`c_ganzhi_code`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `BIOG_MAIN_ibfk_10` FOREIGN KEY (`c_ethnicity_code`) REFERENCES `ETHNICITY_TRIBE_CODES` (`c_ethnicity_code`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `BIOG_MAIN_ibfk_11` FOREIGN KEY (`c_fl_ey_nh_code`) REFERENCES `NIAN_HAO` (`c_nianhao_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `BIOG_MAIN_ibfk_12` FOREIGN KEY (`c_fl_ly_nh_code`) REFERENCES `NIAN_HAO` (`c_nianhao_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `BIOG_MAIN_ibfk_13` FOREIGN KEY (`c_household_status_code`) REFERENCES `HOUSEHOLD_STATUS_CODES` (`c_household_status_code`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `BIOG_MAIN_ibfk_2` FOREIGN KEY (`c_by_nh_code`) REFERENCES `NIAN_HAO` (`c_nianhao_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `BIOG_MAIN_ibfk_3` FOREIGN KEY (`c_by_range`) REFERENCES `YEAR_RANGE_CODES` (`c_range_code`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `BIOG_MAIN_ibfk_4` FOREIGN KEY (`c_choronym_code`) REFERENCES `CHORONYM_CODES` (`c_choronym_code`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `BIOG_MAIN_ibfk_5` FOREIGN KEY (`c_death_age_range`) REFERENCES `YEAR_RANGE_CODES` (`c_range_code`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `BIOG_MAIN_ibfk_6` FOREIGN KEY (`c_dy`) REFERENCES `DYNASTIES` (`c_dy`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `BIOG_MAIN_ibfk_7` FOREIGN KEY (`c_dy_day_gz`) REFERENCES `GANZHI_CODES` (`c_ganzhi_code`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `BIOG_MAIN_ibfk_8` FOREIGN KEY (`c_dy_nh_code`) REFERENCES `NIAN_HAO` (`c_nianhao_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `BIOG_MAIN_ibfk_9` FOREIGN KEY (`c_dy_range`) REFERENCES `YEAR_RANGE_CODES` (`c_range_code`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `BIOG_SOURCE_DATA`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `BIOG_SOURCE_DATA` (
  `c_personid` int NOT NULL,
  `c_textid` int NOT NULL,
  `c_pages` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `c_notes` longtext COLLATE utf8mb4_general_ci,
  `c_main_source` smallint DEFAULT NULL,
  `c_self_bio` smallint DEFAULT NULL,
  PRIMARY KEY (`c_pages`,`c_personid`,`c_textid`) USING BTREE,
  KEY `c_personid_BIOG_SOURCE_DATA_index` (`c_personid`) USING BTREE,
  KEY `c_textid_BIOG_SOURCE_DATA_index` (`c_textid`) USING BTREE,
  CONSTRAINT `BIOG_SOURCE_DATA_ibfk_1` FOREIGN KEY (`c_personid`) REFERENCES `BIOG_MAIN` (`c_personid`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `BIOG_SOURCE_DATA_ibfk_2` FOREIGN KEY (`c_textid`) REFERENCES `TEXT_CODES` (`c_textid`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `BIOG_TEXT_DATA`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `BIOG_TEXT_DATA` (
  `c_textid` int NOT NULL,
  `c_personid` int NOT NULL,
  `c_role_id` smallint NOT NULL,
  `c_year` smallint DEFAULT NULL,
  `c_nh_code` smallint DEFAULT NULL,
  `c_nh_year` smallint DEFAULT NULL,
  `c_range_code` smallint DEFAULT NULL,
  `c_source` int DEFAULT NULL,
  `c_pages` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_notes` longtext COLLATE utf8mb4_general_ci,
  `c_created_by` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_created_date` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_modified_by` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_modified_date` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  PRIMARY KEY (`c_personid`,`c_role_id`,`c_textid`) USING BTREE,
  KEY `c_textid_TEXT_DATA_index` (`c_textid`) USING BTREE,
  KEY `c_personid_TEXT_DATA_index` (`c_personid`) USING BTREE,
  KEY `c_role_id_TEXT_DATA_index` (`c_role_id`) USING BTREE,
  KEY `c_nh_code_TEXT_DATA_index` (`c_nh_code`) USING BTREE,
  KEY `c_range_code_TEXT_DATA_index` (`c_range_code`) USING BTREE,
  KEY `c_source` (`c_source`),
  CONSTRAINT `TEXT_DATA_ibfk_1` FOREIGN KEY (`c_nh_code`) REFERENCES `NIAN_HAO` (`c_nianhao_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `TEXT_DATA_ibfk_2` FOREIGN KEY (`c_personid`) REFERENCES `BIOG_MAIN` (`c_personid`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `TEXT_DATA_ibfk_3` FOREIGN KEY (`c_range_code`) REFERENCES `YEAR_RANGE_CODES` (`c_range_code`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `TEXT_DATA_ibfk_4` FOREIGN KEY (`c_role_id`) REFERENCES `TEXT_ROLE_CODES` (`c_role_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `TEXT_DATA_ibfk_5` FOREIGN KEY (`c_source`) REFERENCES `TEXT_CODES` (`c_textid`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `TEXT_DATA_ibfk_6` FOREIGN KEY (`c_textid`) REFERENCES `TEXT_CODES` (`c_textid`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `CBDB_NAME_LIST`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `CBDB_NAME_LIST` (
  `c_personid` int DEFAULT NULL,
  `name` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `source` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  KEY `idx_c_personid` (`c_personid`),
  KEY `idx_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `CHORONYM_CODES`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `CHORONYM_CODES` (
  `c_choronym_code` smallint NOT NULL,
  `c_choronym_desc` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_choronym_chn` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  PRIMARY KEY (`c_choronym_code`),
  KEY `c_choronym_code_CHORONYM_CODES_index` (`c_choronym_code`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `COPYMISSINGTABLES`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `COPYMISSINGTABLES` (
  `ID` int DEFAULT NULL,
  `TableName` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `COPYTABLES`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `COPYTABLES` (
  `TableName` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `NotProcessed` smallint DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `COPYTABLESDEFAULT`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `COPYTABLESDEFAULT` (
  `ID` int DEFAULT NULL,
  `TableName` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `COUNTRY_CODES`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `COUNTRY_CODES` (
  `c_country_code` smallint NOT NULL,
  `c_country_desc` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_country_desc_chn` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  PRIMARY KEY (`c_country_code`),
  KEY `c_country_code_COUNTRY_CODES_index` (`c_country_code`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `DYNASTIES`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `DYNASTIES` (
  `c_dy` smallint NOT NULL,
  `c_dynasty` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_dynasty_chn` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_start` smallint DEFAULT NULL,
  `c_end` smallint DEFAULT NULL,
  `c_sort` smallint DEFAULT NULL,
  PRIMARY KEY (`c_dy`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `ENTRY_CODES`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ENTRY_CODES` (
  `c_entry_code` smallint NOT NULL,
  `c_entry_desc` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_entry_desc_chn` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  PRIMARY KEY (`c_entry_code`),
  KEY `c_entry_code_ENTRY_CODES_index` (`c_entry_code`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `ENTRY_CODE_TYPE_REL`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ENTRY_CODE_TYPE_REL` (
  `c_entry_code` smallint NOT NULL,
  `c_entry_type` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  PRIMARY KEY (`c_entry_code`,`c_entry_type`) USING BTREE,
  KEY `c_entry_code_ENTRY_CODE_TYPE_REL_index` (`c_entry_code`) USING BTREE,
  KEY `c_entry_type` (`c_entry_type`) USING BTREE,
  CONSTRAINT `ENTRY_CODE_TYPE_REL_ibfk_1` FOREIGN KEY (`c_entry_code`) REFERENCES `ENTRY_CODES` (`c_entry_code`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `ENTRY_CODE_TYPE_REL_ibfk_2` FOREIGN KEY (`c_entry_type`) REFERENCES `ENTRY_TYPES` (`c_entry_type`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `ENTRY_DATA`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ENTRY_DATA` (
  `c_personid` int NOT NULL,
  `c_entry_code` smallint NOT NULL,
  `c_sequence` smallint NOT NULL,
  `c_exam_rank` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_kin_code` smallint NOT NULL,
  `c_kin_id` int NOT NULL,
  `c_assoc_code` smallint NOT NULL,
  `c_assoc_id` int NOT NULL,
  `c_year` smallint NOT NULL,
  `c_age` smallint DEFAULT NULL,
  `c_nianhao_id` smallint DEFAULT NULL,
  `c_entry_nh_year` smallint DEFAULT NULL,
  `c_entry_range` smallint DEFAULT NULL,
  `c_inst_code` int NOT NULL DEFAULT '0',
  `c_inst_name_code` smallint NOT NULL DEFAULT '0',
  `c_exam_field` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_entry_addr_id` int DEFAULT NULL,
  `c_parental_status` smallint DEFAULT NULL,
  `c_attempt_count` smallint DEFAULT NULL,
  `c_source` int DEFAULT NULL,
  `c_pages` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_notes` longtext COLLATE utf8mb4_general_ci,
  `c_posting_notes` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_created_by` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_created_date` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_modified_by` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_modified_date` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  PRIMARY KEY (`c_assoc_code`,`c_assoc_id`,`c_entry_code`,`c_inst_code`,`c_inst_name_code`,`c_kin_code`,`c_kin_id`,`c_personid`,`c_sequence`,`c_year`) USING BTREE,
  KEY `c_personid_ENTRY_DATA_index` (`c_personid`) USING BTREE,
  KEY `c_entry_code_ENTRY_DATA_index` (`c_entry_code`) USING BTREE,
  KEY `c_kin_code_ENTRY_DATA_index` (`c_kin_code`) USING BTREE,
  KEY `c_kin_id_ENTRY_DATA_index` (`c_kin_id`) USING BTREE,
  KEY `c_assoc_code_ENTRY_DATA_index` (`c_assoc_code`) USING BTREE,
  KEY `c_assoc_id_ENTRY_DATA_index` (`c_assoc_id`) USING BTREE,
  KEY `c_nianhao_id_ENTRY_DATA_index` (`c_nianhao_id`) USING BTREE,
  KEY `c_inst_code_ENTRY_DATA_index` (`c_inst_code`) USING BTREE,
  KEY `c_inst_name_code_ENTRY_DATA_index` (`c_inst_name_code`) USING BTREE,
  KEY `c_entry_addr_id_ENTRY_DATA_index` (`c_entry_addr_id`) USING BTREE,
  KEY `c_entry_range` (`c_entry_range`),
  KEY `c_parental_status` (`c_parental_status`),
  KEY `c_source` (`c_source`),
  CONSTRAINT `ENTRY_DATA_ibfk_1` FOREIGN KEY (`c_assoc_code`) REFERENCES `ASSOC_CODES` (`c_assoc_code`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `ENTRY_DATA_ibfk_10` FOREIGN KEY (`c_parental_status`) REFERENCES `PARENTAL_STATUS_CODES` (`c_parental_status_code`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `ENTRY_DATA_ibfk_11` FOREIGN KEY (`c_personid`) REFERENCES `BIOG_MAIN` (`c_personid`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `ENTRY_DATA_ibfk_12` FOREIGN KEY (`c_source`) REFERENCES `TEXT_CODES` (`c_textid`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `ENTRY_DATA_ibfk_2` FOREIGN KEY (`c_assoc_id`) REFERENCES `BIOG_MAIN` (`c_personid`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `ENTRY_DATA_ibfk_3` FOREIGN KEY (`c_entry_code`) REFERENCES `ENTRY_CODES` (`c_entry_code`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `ENTRY_DATA_ibfk_4` FOREIGN KEY (`c_entry_range`) REFERENCES `YEAR_RANGE_CODES` (`c_range_code`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `ENTRY_DATA_ibfk_5` FOREIGN KEY (`c_inst_name_code`) REFERENCES `SOCIAL_INSTITUTION_NAME_CODES` (`c_inst_name_code`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `ENTRY_DATA_ibfk_6` FOREIGN KEY (`c_inst_code`) REFERENCES `SOCIAL_INSTITUTION_CODES` (`c_inst_code`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `ENTRY_DATA_ibfk_7` FOREIGN KEY (`c_kin_code`) REFERENCES `KINSHIP_CODES` (`c_kincode`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `ENTRY_DATA_ibfk_8` FOREIGN KEY (`c_kin_id`) REFERENCES `BIOG_MAIN` (`c_personid`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `ENTRY_DATA_ibfk_9` FOREIGN KEY (`c_nianhao_id`) REFERENCES `NIAN_HAO` (`c_nianhao_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `ENTRY_TYPES`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ENTRY_TYPES` (
  `c_entry_type` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `c_entry_type_desc` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_entry_type_desc_chn` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_entry_type_parent_id` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_entry_type_level` double DEFAULT NULL,
  `c_entry_type_sortorder` double DEFAULT NULL,
  PRIMARY KEY (`c_entry_type`) USING BTREE,
  KEY `c_entry_type_parent_id_ENTRY_TYPES_index` (`c_entry_type_parent_id`(191)) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `ETHNICITY_TRIBE_CODES`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ETHNICITY_TRIBE_CODES` (
  `c_ethnicity_code` smallint NOT NULL,
  `c_group_code` int DEFAULT NULL,
  `c_subgroup_code` int DEFAULT NULL,
  `c_altname_code` int DEFAULT NULL,
  `c_name_chn` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `c_ethno_legal_cat` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_romanized` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_surname` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_notes` longtext COLLATE utf8mb4_general_ci,
  `JiuTangShu` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `XinTangShu` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `JiuWudaiShi` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `XinWudaiShi` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `SongShi` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `LiaoShi` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `JinShi` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `YuanShi` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `MingShi` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `QingShiGao` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_sortorder` smallint DEFAULT NULL,
  PRIMARY KEY (`c_ethnicity_code`),
  KEY `c_ethnicity_code_ETHNICITY_TRIBE_CODES_index` (`c_ethnicity_code`) USING BTREE,
  KEY `c_group_code_ETHNICITY_TRIBE_CODES_index` (`c_group_code`) USING BTREE,
  KEY `c_subgroup_code_ETHNICITY_TRIBE_CODES_index` (`c_subgroup_code`) USING BTREE,
  KEY `c_altname_code_ETHNICITY_TRIBE_CODES_index` (`c_altname_code`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `EVENTS_ADDR`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `EVENTS_ADDR` (
  `c_event_record_id` int NOT NULL,
  `c_personid` int NOT NULL,
  `c_addr_id` int NOT NULL,
  `c_year` smallint DEFAULT NULL,
  `c_nh_code` smallint DEFAULT NULL,
  `c_nh_year` smallint DEFAULT NULL,
  `c_yr_range` smallint DEFAULT NULL,
  `c_intercalary` smallint DEFAULT NULL,
  `c_month` smallint DEFAULT NULL,
  `c_day` smallint DEFAULT NULL,
  `c_day_ganzhi` smallint DEFAULT NULL,
  PRIMARY KEY (`c_addr_id`,`c_event_record_id`,`c_personid`) USING BTREE,
  KEY `c_event_record_id_EVENTS_ADDR_index` (`c_event_record_id`) USING BTREE,
  KEY `c_personid_EVENTS_ADDR_index` (`c_personid`) USING BTREE,
  KEY `c_addr_id_EVENTS_ADDR_index` (`c_addr_id`) USING BTREE,
  KEY `c_nh_code_EVENTS_ADDR_index` (`c_nh_code`) USING BTREE,
  KEY `c_day_ganzhi` (`c_day_ganzhi`),
  KEY `c_yr_range` (`c_yr_range`),
  CONSTRAINT `EVENTS_ADDR_ibfk_1` FOREIGN KEY (`c_addr_id`) REFERENCES `ADDR_CODES` (`c_addr_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `EVENTS_ADDR_ibfk_2` FOREIGN KEY (`c_day_ganzhi`) REFERENCES `GANZHI_CODES` (`c_ganzhi_code`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `EVENTS_ADDR_ibfk_3` FOREIGN KEY (`c_event_record_id`) REFERENCES `EVENT_CODES` (`c_event_code`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `EVENTS_ADDR_ibfk_4` FOREIGN KEY (`c_nh_code`) REFERENCES `NIAN_HAO` (`c_nianhao_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `EVENTS_ADDR_ibfk_5` FOREIGN KEY (`c_personid`) REFERENCES `BIOG_MAIN` (`c_personid`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `EVENTS_ADDR_ibfk_6` FOREIGN KEY (`c_yr_range`) REFERENCES `YEAR_RANGE_CODES` (`c_range_code`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `EVENTS_DATA`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `EVENTS_DATA` (
  `c_personid` int DEFAULT NULL,
  `c_sequence` smallint DEFAULT NULL,
  `c_event_record_id` int DEFAULT NULL,
  `c_event_code` int DEFAULT NULL,
  `c_role` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_year` smallint DEFAULT NULL,
  `c_nh_code` smallint DEFAULT NULL,
  `c_nh_year` smallint DEFAULT NULL,
  `c_yr_range` smallint DEFAULT NULL,
  `c_intercalary` smallint DEFAULT NULL,
  `c_month` smallint DEFAULT NULL,
  `c_day` smallint DEFAULT NULL,
  `c_day_ganzhi` smallint DEFAULT NULL,
  `c_addr_id` int DEFAULT NULL,
  `c_source` int DEFAULT NULL,
  `c_pages` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_event` longtext COLLATE utf8mb4_general_ci,
  `c_notes` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_created_by` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_created_date` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_modified_by` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_modified_date` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  KEY `c_personid_EVENTS_DATA_index` (`c_personid`) USING BTREE,
  KEY `c_event_record_id_EVENTS_DATA_index` (`c_event_record_id`) USING BTREE,
  KEY `c_event_code_EVENTS_DATA_index` (`c_event_code`) USING BTREE,
  KEY `c_nh_code_EVENTS_DATA_index` (`c_nh_code`) USING BTREE,
  KEY `c_addr_id_EVENTS_DATA_index` (`c_addr_id`) USING BTREE,
  KEY `c_day_ganzhi` (`c_day_ganzhi`),
  KEY `c_source` (`c_source`),
  KEY `c_yr_range` (`c_yr_range`),
  CONSTRAINT `EVENTS_DATA_ibfk_1` FOREIGN KEY (`c_addr_id`) REFERENCES `ADDR_CODES` (`c_addr_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `EVENTS_DATA_ibfk_2` FOREIGN KEY (`c_day_ganzhi`) REFERENCES `GANZHI_CODES` (`c_ganzhi_code`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `EVENTS_DATA_ibfk_3` FOREIGN KEY (`c_event_code`) REFERENCES `EVENT_CODES` (`c_event_code`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `EVENTS_DATA_ibfk_4` FOREIGN KEY (`c_event_record_id`) REFERENCES `EVENT_CODES` (`c_event_code`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `EVENTS_DATA_ibfk_5` FOREIGN KEY (`c_nh_code`) REFERENCES `NIAN_HAO` (`c_nianhao_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `EVENTS_DATA_ibfk_6` FOREIGN KEY (`c_personid`) REFERENCES `BIOG_MAIN` (`c_personid`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `EVENTS_DATA_ibfk_7` FOREIGN KEY (`c_source`) REFERENCES `TEXT_CODES` (`c_textid`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `EVENTS_DATA_ibfk_8` FOREIGN KEY (`c_yr_range`) REFERENCES `YEAR_RANGE_CODES` (`c_range_code`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `EVENT_CODES`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `EVENT_CODES` (
  `c_event_code` int NOT NULL,
  `c_event_name_chn` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_event_name` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_fy_yr` smallint DEFAULT NULL,
  `c_ly_yr` smallint DEFAULT NULL,
  `c_fy_nh_code` smallint DEFAULT NULL,
  `c_ly_nh_code` smallint DEFAULT NULL,
  `c_fy_nh_yr` smallint DEFAULT NULL,
  `c_ly_nh_yr` smallint DEFAULT NULL,
  `c_fy_intercalary` smallint DEFAULT NULL,
  `c_fy_month` smallint DEFAULT NULL,
  `c_ly_intercalary` smallint DEFAULT NULL,
  `c_ly_month` smallint DEFAULT NULL,
  `c_fy_range` smallint DEFAULT NULL,
  `c_ly_range` smallint DEFAULT NULL,
  `c_addr_id` int DEFAULT NULL,
  `c_dy` smallint DEFAULT NULL,
  `c_source` int DEFAULT NULL,
  `c_pages` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_event_notes` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  PRIMARY KEY (`c_event_code`) USING BTREE,
  KEY `c_event_code_EVENT_CODES_index` (`c_event_code`) USING BTREE,
  KEY `c_fy_nh_code_EVENT_CODES_index` (`c_fy_nh_code`) USING BTREE,
  KEY `c_ly_nh_code_EVENT_CODES_index` (`c_ly_nh_code`) USING BTREE,
  KEY `c_addr_id_EVENT_CODES_index` (`c_addr_id`) USING BTREE,
  KEY `c_dy` (`c_dy`),
  KEY `c_fy_range` (`c_fy_range`),
  KEY `c_ly_range` (`c_ly_range`),
  KEY `c_source` (`c_source`),
  CONSTRAINT `EVENT_CODES_ibfk_1` FOREIGN KEY (`c_addr_id`) REFERENCES `ADDR_CODES` (`c_addr_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `EVENT_CODES_ibfk_2` FOREIGN KEY (`c_dy`) REFERENCES `DYNASTIES` (`c_dy`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `EVENT_CODES_ibfk_3` FOREIGN KEY (`c_fy_nh_code`) REFERENCES `NIAN_HAO` (`c_nianhao_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `EVENT_CODES_ibfk_4` FOREIGN KEY (`c_fy_range`) REFERENCES `YEAR_RANGE_CODES` (`c_range_code`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `EVENT_CODES_ibfk_5` FOREIGN KEY (`c_ly_nh_code`) REFERENCES `NIAN_HAO` (`c_nianhao_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `EVENT_CODES_ibfk_6` FOREIGN KEY (`c_ly_range`) REFERENCES `YEAR_RANGE_CODES` (`c_range_code`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `EVENT_CODES_ibfk_7` FOREIGN KEY (`c_source`) REFERENCES `TEXT_CODES` (`c_textid`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `EXTANT_CODES`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `EXTANT_CODES` (
  `c_extant_code` smallint NOT NULL,
  `c_extant_desc` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_extant_desc_chn` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_extant_code_hd` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  PRIMARY KEY (`c_extant_code`) USING BTREE,
  KEY `c_extant_code_EXTANT_CODES_index` (`c_extant_code`) USING BTREE,
  KEY `c_extant_code_hd_EXTANT_CODES_index` (`c_extant_code_hd`(191)) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `FOREIGNKEYS`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `FOREIGNKEYS` (
  `AccessTblNm` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `AccessFldNm` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `ForeignKey` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `ForeignKeyBaseField` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `FKString` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `FKName` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `skip` smallint DEFAULT NULL,
  `IndexOnField` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `DataFormat` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `NULL_allowed` smallint DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `FORMLABELS`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `FORMLABELS` (
  `c_form` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_label_id` smallint DEFAULT NULL,
  `c_english` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_jianti` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_fanti` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  KEY `c_label_id_FormLabels_index` (`c_label_id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `GANZHI_CODES`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `GANZHI_CODES` (
  `c_ganzhi_code` smallint NOT NULL,
  `c_ganzhi_chn` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_ganzhi_py` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  PRIMARY KEY (`c_ganzhi_code`) USING BTREE,
  KEY `c_ganzhi_code_GANZHI_CODES_index` (`c_ganzhi_code`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `HOUSEHOLD_STATUS_CODES`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `HOUSEHOLD_STATUS_CODES` (
  `c_household_status_code` smallint NOT NULL,
  `c_household_status_desc` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_household_status_desc_chn` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  PRIMARY KEY (`c_household_status_code`) USING BTREE,
  KEY `c_household_status_code_HOUSEHOLD_STATUS_CODES_index` (`c_household_status_code`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `INDEXYEAR_TYPE_CODES`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `INDEXYEAR_TYPE_CODES` (
  `c_index_year_type_code` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_index_year_type_desc` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_index_year_type_hz` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_notes` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `KINSHIP_CODES`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `KINSHIP_CODES` (
  `c_kincode` smallint NOT NULL,
  `c_kin_pair1` smallint DEFAULT NULL,
  `c_kin_pair2` smallint DEFAULT NULL,
  `c_kin_pair_notes` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_kinrel_chn` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_kinrel` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_kinrel_alt` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_pick_sorting` smallint DEFAULT NULL,
  `c_upstep` smallint DEFAULT NULL,
  `c_dwnstep` smallint DEFAULT NULL,
  `c_marstep` smallint DEFAULT NULL,
  `c_colstep` smallint DEFAULT NULL,
  `c_kinrel_simplified` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  PRIMARY KEY (`c_kincode`) USING BTREE,
  KEY `c_kincode_KINSHIP_CODES_index` (`c_kincode`) USING BTREE,
  KEY `c_kin_pair1` (`c_kin_pair1`),
  KEY `c_kin_pair2` (`c_kin_pair2`),
  CONSTRAINT `KINSHIP_CODES_ibfk_1` FOREIGN KEY (`c_kin_pair1`) REFERENCES `KINSHIP_CODES` (`c_kincode`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `KINSHIP_CODES_ibfk_2` FOREIGN KEY (`c_kin_pair2`) REFERENCES `KINSHIP_CODES` (`c_kincode`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `KIN_DATA`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `KIN_DATA` (
  `c_personid` int NOT NULL,
  `c_kin_id` int NOT NULL,
  `c_kin_code` smallint NOT NULL,
  `c_source` int DEFAULT NULL,
  `c_pages` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_notes` longtext COLLATE utf8mb4_general_ci,
  `c_autogen_notes` longtext COLLATE utf8mb4_general_ci,
  `c_created_by` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_created_date` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_modified_by` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_modified_date` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  PRIMARY KEY (`c_kin_code`,`c_kin_id`,`c_personid`),
  KEY `c_personid_KIN_DATA_index` (`c_personid`) USING BTREE,
  KEY `c_kin_id_KIN_DATA_index` (`c_kin_id`) USING BTREE,
  KEY `c_kin_code_KIN_DATA_index` (`c_kin_code`) USING BTREE,
  KEY `c_source` (`c_source`),
  CONSTRAINT `KIN_DATA_ibfk_1` FOREIGN KEY (`c_kin_code`) REFERENCES `KINSHIP_CODES` (`c_kincode`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `KIN_DATA_ibfk_2` FOREIGN KEY (`c_kin_id`) REFERENCES `BIOG_MAIN` (`c_personid`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `KIN_DATA_ibfk_3` FOREIGN KEY (`c_personid`) REFERENCES `BIOG_MAIN` (`c_personid`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `KIN_DATA_ibfk_4` FOREIGN KEY (`c_source`) REFERENCES `TEXT_CODES` (`c_textid`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `KIN_MOURNING`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `KIN_MOURNING` (
  `c_kinrel` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `c_kinrel_alt` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_kinrel_chn` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_mourning` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_mourning_chn` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_kindist` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_kintype` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_kintype_desc` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_kintype_desc_chn` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_notes` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  PRIMARY KEY (`c_kinrel`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `KIN_MOURNING_STEPS`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `KIN_MOURNING_STEPS` (
  `c_kinrel` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `c_upstep` smallint DEFAULT NULL,
  `c_dwnstep` smallint DEFAULT NULL,
  `c_marstep` smallint DEFAULT NULL,
  `c_colstep` smallint DEFAULT NULL,
  PRIMARY KEY (`c_kinrel`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `LITERARYGENRE_CODES`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `LITERARYGENRE_CODES` (
  `c_lit_genre_code` int NOT NULL,
  `c_lit_genre_desc` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_lit_genre_desc_chn` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_sortorder` int DEFAULT NULL,
  PRIMARY KEY (`c_lit_genre_code`) USING BTREE,
  KEY `c_lit_genre_code_LITERARYGENRE_CODES_index` (`c_lit_genre_code`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `MEASURE_CODES`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `MEASURE_CODES` (
  `c_measure_code` smallint NOT NULL,
  `c_measure_desc` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_measure_desc_chn` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  PRIMARY KEY (`c_measure_code`) USING BTREE,
  KEY `c_measure_code_MEASURE_CODES_index` (`c_measure_code`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `MERGED_PERSON_DATA`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `MERGED_PERSON_DATA` (
  `c_personid` int NOT NULL,
  `c_merged_to_personid` int NOT NULL,
  `c_notes` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `c_source` int DEFAULT NULL,
  `c_pages` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_created_by` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_created_date` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_modified_by` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_modified_date` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  PRIMARY KEY (`c_personid`,`c_merged_to_personid`) USING BTREE,
  KEY `idx_merged_to_personid` (`c_merged_to_personid`) USING BTREE,
  KEY `idx_source` (`c_source`) USING BTREE,
  CONSTRAINT `fk_merged_person_source` FOREIGN KEY (`c_source`) REFERENCES `TEXT_CODES` (`c_textid`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `NIAN_HAO`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `NIAN_HAO` (
  `c_nianhao_id` smallint NOT NULL,
  `c_dy` smallint DEFAULT NULL,
  `c_dynasty_chn` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_nianhao_chn` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_nianhao_pin` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_firstyear` smallint DEFAULT NULL,
  `c_lastyear` smallint DEFAULT NULL,
  PRIMARY KEY (`c_nianhao_id`) USING BTREE,
  KEY `c_nianhao_id_NIAN_HAO_index` (`c_nianhao_id`) USING BTREE,
  KEY `c_dy` (`c_dy`),
  CONSTRAINT `NIAN_HAO_ibfk_1` FOREIGN KEY (`c_dy`) REFERENCES `DYNASTIES` (`c_dy`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `OCCASION_CODES`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `OCCASION_CODES` (
  `c_occasion_code` int NOT NULL,
  `c_occasion_desc` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_occasion_desc_chn` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_sortorder` int DEFAULT NULL,
  PRIMARY KEY (`c_occasion_code`) USING BTREE,
  KEY `c_occasion_code_OCCASION_CODES_index` (`c_occasion_code`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `OFFICE_CATEGORIES`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `OFFICE_CATEGORIES` (
  `c_office_category_id` smallint NOT NULL,
  `c_category_desc` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_category_desc_chn` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_notes` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  PRIMARY KEY (`c_office_category_id`) USING BTREE,
  KEY `c_office_category_id_OFFICE_CATEGORIES_index` (`c_office_category_id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `OFFICE_CODES`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `OFFICE_CODES` (
  `c_office_id` int NOT NULL,
  `c_dy` smallint DEFAULT NULL,
  `c_office_pinyin` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_office_chn` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_office_pinyin_alt` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_office_chn_alt` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_office_trans` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_office_trans_alt` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_source` int DEFAULT NULL,
  `c_pages` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_notes` longtext COLLATE utf8mb4_general_ci,
  `c_category_1` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_category_2` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_category_3` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_category_4` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_office_id_old` int DEFAULT NULL,
  PRIMARY KEY (`c_office_id`) USING BTREE,
  KEY `c_office_id_OFFICE_CODES_index` (`c_office_id`) USING BTREE,
  KEY `c_office_id_old_OFFICE_CODES_index` (`c_office_id_old`) USING BTREE,
  KEY `c_dy` (`c_dy`),
  KEY `c_source` (`c_source`),
  CONSTRAINT `OFFICE_CODES_ibfk_1` FOREIGN KEY (`c_dy`) REFERENCES `DYNASTIES` (`c_dy`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `OFFICE_CODES_ibfk_2` FOREIGN KEY (`c_source`) REFERENCES `TEXT_CODES` (`c_textid`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `OFFICE_CODE_TYPE_REL`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `OFFICE_CODE_TYPE_REL` (
  `c_office_id` int NOT NULL,
  `c_office_tree_id` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  PRIMARY KEY (`c_office_id`,`c_office_tree_id`) USING BTREE,
  KEY `c_office_id_OFFICE_CODE_TYPE_REL_index` (`c_office_id`) USING BTREE,
  KEY `c_office_tree_id_OFFICE_CODE_TYPE_REL_index` (`c_office_tree_id`(191)) USING BTREE,
  KEY `c_office_tree_id` (`c_office_tree_id`) USING BTREE,
  CONSTRAINT `OFFICE_CODE_TYPE_REL_ibfk_1` FOREIGN KEY (`c_office_id`) REFERENCES `OFFICE_CODES` (`c_office_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `OFFICE_CODE_TYPE_REL_ibfk_2` FOREIGN KEY (`c_office_tree_id`) REFERENCES `OFFICE_TYPE_TREE` (`c_office_type_node_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `OFFICE_TYPE_TREE`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `OFFICE_TYPE_TREE` (
  `c_office_type_node_id` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `c_office_type_desc` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_office_type_desc_chn` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_parent_id` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  PRIMARY KEY (`c_office_type_node_id`) USING BTREE,
  KEY `c_office_type_node_id_OFFICE_TYPE_TREE_index` (`c_office_type_node_id`(191)) USING BTREE,
  KEY `c_parent_id_OFFICE_TYPE_TREE_index` (`c_parent_id`(191)) USING BTREE,
  KEY `c_parent_id` (`c_parent_id`),
  CONSTRAINT `OFFICE_TYPE_TREE_ibfk_1` FOREIGN KEY (`c_parent_id`) REFERENCES `OFFICE_TYPE_TREE` (`c_office_type_node_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `PARENTAL_STATUS_CODES`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `PARENTAL_STATUS_CODES` (
  `c_parental_status_code` smallint NOT NULL,
  `c_parental_status_desc` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_parental_status_desc_chn` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  PRIMARY KEY (`c_parental_status_code`) USING BTREE,
  KEY `c_parental_status_code_PARENTAL_STATUS_CODES_index` (`c_parental_status_code`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `PLACE_CODES`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `PLACE_CODES` (
  `c_place_id` double DEFAULT NULL,
  `c_place_1990` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `c_name_chn` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `x_coord` double DEFAULT NULL,
  `y_coord` double DEFAULT NULL,
  KEY `c_place_id_PLACE_CODES_index` (`c_place_id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `POSSESSION_ACT_CODES`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `POSSESSION_ACT_CODES` (
  `c_possession_act_code` smallint NOT NULL,
  `c_possession_act_desc` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_possession_act_desc_chn` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  PRIMARY KEY (`c_possession_act_code`) USING BTREE,
  KEY `c_possession_act_code_POSSESSION_ACT_CODES_index` (`c_possession_act_code`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `POSSESSION_ADDR`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `POSSESSION_ADDR` (
  `c_possession_record_id` int NOT NULL,
  `c_personid` int NOT NULL,
  `c_addr_id` int NOT NULL,
  PRIMARY KEY (`c_addr_id`,`c_personid`,`c_possession_record_id`) USING BTREE,
  KEY `c_possession_record_id_POSSESSION_ADDR_index` (`c_possession_record_id`) USING BTREE,
  KEY `c_personid_POSSESSION_ADDR_index` (`c_personid`) USING BTREE,
  KEY `c_addr_id_POSSESSION_ADDR_index` (`c_addr_id`) USING BTREE,
  CONSTRAINT `POSSESSION_ADDR_ibfk_1` FOREIGN KEY (`c_addr_id`) REFERENCES `ADDR_CODES` (`c_addr_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `POSSESSION_ADDR_ibfk_2` FOREIGN KEY (`c_personid`) REFERENCES `BIOG_MAIN` (`c_personid`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `POSSESSION_ADDR_ibfk_3` FOREIGN KEY (`c_possession_record_id`) REFERENCES `POSSESSION_DATA` (`c_possession_record_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `POSSESSION_DATA`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `POSSESSION_DATA` (
  `c_personid` int DEFAULT NULL,
  `c_possession_record_id` int NOT NULL,
  `c_sequence` int DEFAULT NULL,
  `c_possession_act_code` smallint DEFAULT NULL,
  `c_possession_desc` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_possession_desc_chn` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_quantity` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_measure_code` smallint DEFAULT NULL,
  `c_possession_yr` smallint DEFAULT NULL,
  `c_possession_nh_code` smallint DEFAULT NULL,
  `c_possession_nh_yr` smallint DEFAULT NULL,
  `c_possession_yr_range` smallint DEFAULT NULL,
  `c_addr_id` int DEFAULT NULL,
  `c_source` int DEFAULT NULL,
  `c_pages` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_notes` longtext COLLATE utf8mb4_general_ci,
  `c_created_by` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_created_date` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_modified_by` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_modified_date` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  PRIMARY KEY (`c_possession_record_id`) USING BTREE,
  KEY `c_personid_POSSESSION_DATA_index` (`c_personid`) USING BTREE,
  KEY `c_possession_record_id_POSSESSION_DATA_index` (`c_possession_record_id`) USING BTREE,
  KEY `c_possession_act_code_POSSESSION_DATA_index` (`c_possession_act_code`) USING BTREE,
  KEY `c_measure_code_POSSESSION_DATA_index` (`c_measure_code`) USING BTREE,
  KEY `c_possession_nh_code_POSSESSION_DATA_index` (`c_possession_nh_code`) USING BTREE,
  KEY `c_addr_id_POSSESSION_DATA_index` (`c_addr_id`) USING BTREE,
  KEY `c_possession_yr_range` (`c_possession_yr_range`),
  KEY `c_source` (`c_source`),
  CONSTRAINT `POSSESSION_DATA_ibfk_1` FOREIGN KEY (`c_addr_id`) REFERENCES `ADDR_CODES` (`c_addr_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `POSSESSION_DATA_ibfk_2` FOREIGN KEY (`c_measure_code`) REFERENCES `MEASURE_CODES` (`c_measure_code`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `POSSESSION_DATA_ibfk_3` FOREIGN KEY (`c_personid`) REFERENCES `BIOG_MAIN` (`c_personid`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `POSSESSION_DATA_ibfk_4` FOREIGN KEY (`c_possession_act_code`) REFERENCES `POSSESSION_ACT_CODES` (`c_possession_act_code`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `POSSESSION_DATA_ibfk_5` FOREIGN KEY (`c_possession_nh_code`) REFERENCES `NIAN_HAO` (`c_nianhao_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `POSSESSION_DATA_ibfk_6` FOREIGN KEY (`c_possession_yr_range`) REFERENCES `YEAR_RANGE_CODES` (`c_range_code`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `POSSESSION_DATA_ibfk_7` FOREIGN KEY (`c_source`) REFERENCES `TEXT_CODES` (`c_textid`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `POSTED_TO_ADDR_DATA`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `POSTED_TO_ADDR_DATA` (
  `c_posting_id` int NOT NULL,
  `c_personid` int DEFAULT NULL,
  `c_office_id` int NOT NULL,
  `c_addr_id` int NOT NULL,
  `c_posting_id_old` int DEFAULT NULL,
  PRIMARY KEY (`c_addr_id`,`c_office_id`,`c_posting_id`) USING BTREE,
  KEY `c_posting_id_POSTED_TO_ADDR_DATA_index` (`c_posting_id`) USING BTREE,
  KEY `c_personid_POSTED_TO_ADDR_DATA_index` (`c_personid`) USING BTREE,
  KEY `c_office_id_POSTED_TO_ADDR_DATA_index` (`c_office_id`) USING BTREE,
  KEY `c_addr_id_POSTED_TO_ADDR_DATA_index` (`c_addr_id`) USING BTREE,
  KEY `c_posting_id_old_POSTED_TO_ADDR_DATA_index` (`c_posting_id_old`) USING BTREE,
  CONSTRAINT `POSTED_TO_ADDR_DATA_ibfk_1` FOREIGN KEY (`c_addr_id`) REFERENCES `ADDR_CODES` (`c_addr_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `POSTED_TO_ADDR_DATA_ibfk_2` FOREIGN KEY (`c_office_id`) REFERENCES `OFFICE_CODES` (`c_office_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `POSTED_TO_ADDR_DATA_ibfk_3` FOREIGN KEY (`c_personid`) REFERENCES `BIOG_MAIN` (`c_personid`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `POSTED_TO_ADDR_DATA_ibfk_4` FOREIGN KEY (`c_posting_id`) REFERENCES `POSTING_DATA` (`c_posting_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `POSTED_TO_OFFICE_DATA`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `POSTED_TO_OFFICE_DATA` (
  `c_personid` int DEFAULT NULL,
  `c_office_id` int NOT NULL,
  `c_posting_id` int NOT NULL,
  `c_posting_id_old` int DEFAULT NULL,
  `c_sequence` smallint DEFAULT NULL,
  `c_firstyear` smallint DEFAULT NULL,
  `c_fy_nh_code` smallint DEFAULT NULL,
  `c_fy_nh_year` smallint DEFAULT NULL,
  `c_fy_range` smallint DEFAULT NULL,
  `c_lastyear` smallint DEFAULT NULL,
  `c_ly_nh_code` smallint DEFAULT NULL,
  `c_ly_nh_year` smallint DEFAULT NULL,
  `c_ly_range` smallint DEFAULT NULL,
  `c_appt_type_code` smallint DEFAULT NULL,
  `c_assume_office_code` smallint DEFAULT NULL,
  `c_inst_code` int DEFAULT '0',
  `c_inst_name_code` smallint DEFAULT '0',
  `c_source` int DEFAULT NULL,
  `c_pages` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_notes` longtext COLLATE utf8mb4_general_ci,
  `c_office_id_backup` int DEFAULT NULL,
  `c_office_category_id` smallint DEFAULT NULL,
  `c_fy_intercalary` smallint DEFAULT NULL,
  `c_fy_month` smallint DEFAULT NULL,
  `c_ly_intercalary` smallint DEFAULT NULL,
  `c_ly_month` smallint DEFAULT NULL,
  `c_fy_day` smallint DEFAULT NULL,
  `c_ly_day` int DEFAULT NULL,
  `c_fy_day_gz` smallint DEFAULT NULL,
  `c_ly_day_gz` smallint DEFAULT NULL,
  `c_dy` smallint DEFAULT NULL,
  `c_created_by` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_created_date` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_modified_by` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_modified_date` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_appt_code` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  PRIMARY KEY (`c_office_id`,`c_posting_id`) USING BTREE,
  KEY `c_personid_POSTED_TO_OFFICE_DATA_index` (`c_personid`) USING BTREE,
  KEY `c_office_id_POSTED_TO_OFFICE_DATA_index` (`c_office_id`) USING BTREE,
  KEY `c_posting_id_POSTED_TO_OFFICE_DATA_index` (`c_posting_id`) USING BTREE,
  KEY `c_posting_id_old_POSTED_TO_OFFICE_DATA_index` (`c_posting_id_old`) USING BTREE,
  KEY `c_fy_nh_code_POSTED_TO_OFFICE_DATA_index` (`c_fy_nh_code`) USING BTREE,
  KEY `c_ly_nh_code_POSTED_TO_OFFICE_DATA_index` (`c_ly_nh_code`) USING BTREE,
  KEY `c_appt_type_code_POSTED_TO_OFFICE_DATA_index` (`c_appt_type_code`) USING BTREE,
  KEY `c_assume_office_code_POSTED_TO_OFFICE_DATA_index` (`c_assume_office_code`) USING BTREE,
  KEY `c_inst_code_POSTED_TO_OFFICE_DATA_index` (`c_inst_code`) USING BTREE,
  KEY `c_inst_name_code_POSTED_TO_OFFICE_DATA_index` (`c_inst_name_code`) USING BTREE,
  KEY `c_office_id_backup_POSTED_TO_OFFICE_DATA_index` (`c_office_id_backup`) USING BTREE,
  KEY `c_office_category_id_POSTED_TO_OFFICE_DATA_index` (`c_office_category_id`) USING BTREE,
  KEY `c_dy` (`c_dy`),
  KEY `c_fy_day_gz` (`c_fy_day_gz`),
  KEY `c_fy_range` (`c_fy_range`),
  KEY `c_ly_day_gz` (`c_ly_day_gz`),
  KEY `c_ly_range` (`c_ly_range`),
  KEY `c_source` (`c_source`),
  CONSTRAINT `POSTED_TO_OFFICE_DATA_ibfk_1` FOREIGN KEY (`c_appt_type_code`) REFERENCES `APPOINTMENT_CODES` (`c_appt_code`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `POSTED_TO_OFFICE_DATA_ibfk_10` FOREIGN KEY (`c_ly_nh_code`) REFERENCES `NIAN_HAO` (`c_nianhao_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `POSTED_TO_OFFICE_DATA_ibfk_11` FOREIGN KEY (`c_ly_range`) REFERENCES `YEAR_RANGE_CODES` (`c_range_code`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `POSTED_TO_OFFICE_DATA_ibfk_12` FOREIGN KEY (`c_office_category_id`) REFERENCES `OFFICE_CATEGORIES` (`c_office_category_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `POSTED_TO_OFFICE_DATA_ibfk_13` FOREIGN KEY (`c_office_id`) REFERENCES `OFFICE_CODES` (`c_office_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `POSTED_TO_OFFICE_DATA_ibfk_14` FOREIGN KEY (`c_personid`) REFERENCES `BIOG_MAIN` (`c_personid`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `POSTED_TO_OFFICE_DATA_ibfk_15` FOREIGN KEY (`c_posting_id`) REFERENCES `POSTING_DATA` (`c_posting_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `POSTED_TO_OFFICE_DATA_ibfk_16` FOREIGN KEY (`c_source`) REFERENCES `TEXT_CODES` (`c_textid`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `POSTED_TO_OFFICE_DATA_ibfk_2` FOREIGN KEY (`c_assume_office_code`) REFERENCES `ASSUME_OFFICE_CODES` (`c_assume_office_code`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `POSTED_TO_OFFICE_DATA_ibfk_3` FOREIGN KEY (`c_dy`) REFERENCES `DYNASTIES` (`c_dy`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `POSTED_TO_OFFICE_DATA_ibfk_4` FOREIGN KEY (`c_fy_day_gz`) REFERENCES `GANZHI_CODES` (`c_ganzhi_code`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `POSTED_TO_OFFICE_DATA_ibfk_5` FOREIGN KEY (`c_fy_nh_code`) REFERENCES `NIAN_HAO` (`c_nianhao_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `POSTED_TO_OFFICE_DATA_ibfk_6` FOREIGN KEY (`c_fy_range`) REFERENCES `YEAR_RANGE_CODES` (`c_range_code`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `POSTED_TO_OFFICE_DATA_ibfk_7` FOREIGN KEY (`c_inst_name_code`) REFERENCES `SOCIAL_INSTITUTION_NAME_CODES` (`c_inst_name_code`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `POSTED_TO_OFFICE_DATA_ibfk_8` FOREIGN KEY (`c_inst_code`) REFERENCES `SOCIAL_INSTITUTION_CODES` (`c_inst_code`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `POSTED_TO_OFFICE_DATA_ibfk_9` FOREIGN KEY (`c_ly_day_gz`) REFERENCES `GANZHI_CODES` (`c_ganzhi_code`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `POSTING_DATA`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `POSTING_DATA` (
  `c_personid` int DEFAULT NULL,
  `c_posting_id` int NOT NULL,
  `c_posting_id_old` int DEFAULT NULL,
  PRIMARY KEY (`c_posting_id`) USING BTREE,
  KEY `c_personid_POSTING_DATA_index` (`c_personid`) USING BTREE,
  KEY `c_posting_id_POSTING_DATA_index` (`c_posting_id`) USING BTREE,
  KEY `c_posting_id_old_POSTING_DATA_index` (`c_posting_id_old`) USING BTREE,
  CONSTRAINT `POSTING_DATA_ibfk_1` FOREIGN KEY (`c_personid`) REFERENCES `BIOG_MAIN` (`c_personid`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `SCHOLARLYTOPIC_CODES`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `SCHOLARLYTOPIC_CODES` (
  `c_topic_code` int NOT NULL,
  `c_topic_desc` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_topic_desc_chn` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_topic_type_code` int DEFAULT NULL,
  `c_topic_type_desc` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_topic_type_desc_chn` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_sortorder` int DEFAULT NULL,
  PRIMARY KEY (`c_topic_code`) USING BTREE,
  KEY `c_topic_code_SCHOLARLYTOPIC_CODES_index` (`c_topic_code`) USING BTREE,
  KEY `c_topic_type_code_SCHOLARLYTOPIC_CODES_index` (`c_topic_type_code`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `SOCIAL_INSTITUTION_ADDR`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `SOCIAL_INSTITUTION_ADDR` (
  `c_inst_name_code` smallint NOT NULL,
  `c_inst_code` int NOT NULL,
  `c_inst_addr_type_code` smallint NOT NULL,
  `c_inst_addr_begin_year` smallint DEFAULT NULL,
  `c_inst_addr_end_year` smallint DEFAULT NULL,
  `c_inst_addr_id` int NOT NULL,
  `inst_xcoord` double NOT NULL,
  `inst_ycoord` double NOT NULL,
  `c_source` int DEFAULT NULL,
  `c_pages` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_notes` longtext COLLATE utf8mb4_general_ci,
  PRIMARY KEY (`c_inst_addr_id`,`c_inst_addr_type_code`,`c_inst_code`,`c_inst_name_code`,`inst_xcoord`,`inst_ycoord`) USING BTREE,
  KEY `c_inst_name_code_SOCIAL_INSTITUTION_ADDR_index` (`c_inst_name_code`) USING BTREE,
  KEY `c_inst_code_SOCIAL_INSTITUTION_ADDR_index` (`c_inst_code`) USING BTREE,
  KEY `c_inst_addr_type_code_SOCIAL_INSTITUTION_ADDR_index` (`c_inst_addr_type_code`) USING BTREE,
  KEY `c_inst_addr_id_SOCIAL_INSTITUTION_ADDR_index` (`c_inst_addr_id`) USING BTREE,
  KEY `c_source` (`c_source`),
  CONSTRAINT `SOCIAL_INSTITUTION_ADDR_ibfk_1` FOREIGN KEY (`c_inst_addr_id`) REFERENCES `ADDR_CODES` (`c_addr_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `SOCIAL_INSTITUTION_ADDR_ibfk_2` FOREIGN KEY (`c_inst_addr_type_code`) REFERENCES `SOCIAL_INSTITUTION_ADDR_TYPES` (`c_inst_addr_type_code`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `SOCIAL_INSTITUTION_ADDR_ibfk_3` FOREIGN KEY (`c_inst_code`) REFERENCES `SOCIAL_INSTITUTION_CODES` (`c_inst_code`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `SOCIAL_INSTITUTION_ADDR_ibfk_4` FOREIGN KEY (`c_source`) REFERENCES `TEXT_CODES` (`c_textid`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `SOCIAL_INSTITUTION_ADDR_TYPES`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `SOCIAL_INSTITUTION_ADDR_TYPES` (
  `c_inst_addr_type_code` smallint NOT NULL,
  `c_inst_addr_type_desc` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_inst_addr_type_chn` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_notes` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  PRIMARY KEY (`c_inst_addr_type_code`) USING BTREE,
  KEY `c_inst_addr_type_code_SOCIAL_INSTITUTION_ADDR_TYPES_index` (`c_inst_addr_type_code`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `SOCIAL_INSTITUTION_ALTNAME_CODES`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `SOCIAL_INSTITUTION_ALTNAME_CODES` (
  `c_inst_altname_type` smallint DEFAULT NULL,
  `c_inst_altname_desc` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_inst_altname_chn` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_notes` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `SOCIAL_INSTITUTION_ALTNAME_DATA`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `SOCIAL_INSTITUTION_ALTNAME_DATA` (
  `c_inst_name_code` smallint DEFAULT NULL,
  `c_inst_code` smallint DEFAULT NULL,
  `c_inst_altname_type` smallint DEFAULT NULL,
  `c_inst_altname_hz` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_inst_altname_py` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_source` int DEFAULT NULL,
  `c_pages` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_notes` longtext COLLATE utf8mb4_general_ci,
  `c_secondary_source_author` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  KEY `c_inst_name_code_SOCIAL_INSTITUTION_ALTNAME_DATA_index` (`c_inst_name_code`) USING BTREE,
  KEY `c_inst_code_SOCIAL_INSTITUTION_ALTNAME_DATA_index` (`c_inst_code`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `SOCIAL_INSTITUTION_CODES`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `SOCIAL_INSTITUTION_CODES` (
  `c_inst_name_code` smallint NOT NULL,
  `c_inst_code` int NOT NULL,
  `c_inst_type_code` smallint DEFAULT NULL,
  `c_inst_begin_year` smallint DEFAULT NULL,
  `c_by_nianhao_code` smallint DEFAULT NULL,
  `c_by_nianhao_year` smallint DEFAULT NULL,
  `c_by_year_range` smallint DEFAULT NULL,
  `c_inst_begin_dy` smallint DEFAULT NULL,
  `c_inst_floruit_dy` smallint DEFAULT NULL,
  `c_inst_first_known_year` smallint DEFAULT NULL,
  `c_inst_end_year` smallint DEFAULT NULL,
  `c_ey_nianhao_code` smallint DEFAULT NULL,
  `c_ey_nianhao_year` smallint DEFAULT NULL,
  `c_ey_year_range` smallint DEFAULT NULL,
  `c_inst_end_dy` smallint DEFAULT NULL,
  `c_inst_last_known_year` smallint DEFAULT NULL,
  `c_source` int DEFAULT NULL,
  `c_pages` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_notes` longtext COLLATE utf8mb4_general_ci,
  PRIMARY KEY (`c_inst_code`,`c_inst_name_code`) USING BTREE,
  KEY `c_inst_name_code_SOCIAL_INSTITUTION_CODES_index` (`c_inst_name_code`) USING BTREE,
  KEY `c_inst_code_SOCIAL_INSTITUTION_CODES_index` (`c_inst_code`) USING BTREE,
  KEY `c_inst_type_code_SOCIAL_INSTITUTION_CODES_index` (`c_inst_type_code`) USING BTREE,
  KEY `c_by_nianhao_code_SOCIAL_INSTITUTION_CODES_index` (`c_by_nianhao_code`) USING BTREE,
  KEY `c_ey_nianhao_code_SOCIAL_INSTITUTION_CODES_index` (`c_ey_nianhao_code`) USING BTREE,
  KEY `c_by_year_range` (`c_by_year_range`),
  KEY `c_ey_year_range` (`c_ey_year_range`),
  KEY `c_inst_begin_dy` (`c_inst_begin_dy`),
  KEY `c_inst_floruit_dy` (`c_inst_floruit_dy`),
  KEY `c_source` (`c_source`),
  CONSTRAINT `SOCIAL_INSTITUTION_CODES_ibfk_1` FOREIGN KEY (`c_by_nianhao_code`) REFERENCES `NIAN_HAO` (`c_nianhao_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `SOCIAL_INSTITUTION_CODES_ibfk_2` FOREIGN KEY (`c_by_year_range`) REFERENCES `YEAR_RANGE_CODES` (`c_range_code`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `SOCIAL_INSTITUTION_CODES_ibfk_3` FOREIGN KEY (`c_ey_nianhao_code`) REFERENCES `NIAN_HAO` (`c_nianhao_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `SOCIAL_INSTITUTION_CODES_ibfk_4` FOREIGN KEY (`c_ey_year_range`) REFERENCES `YEAR_RANGE_CODES` (`c_range_code`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `SOCIAL_INSTITUTION_CODES_ibfk_5` FOREIGN KEY (`c_inst_begin_dy`) REFERENCES `DYNASTIES` (`c_dy`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `SOCIAL_INSTITUTION_CODES_ibfk_6` FOREIGN KEY (`c_inst_floruit_dy`) REFERENCES `DYNASTIES` (`c_dy`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `SOCIAL_INSTITUTION_CODES_ibfk_7` FOREIGN KEY (`c_inst_name_code`) REFERENCES `SOCIAL_INSTITUTION_NAME_CODES` (`c_inst_name_code`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `SOCIAL_INSTITUTION_CODES_ibfk_8` FOREIGN KEY (`c_inst_type_code`) REFERENCES `SOCIAL_INSTITUTION_TYPES` (`c_inst_type_code`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `SOCIAL_INSTITUTION_CODES_ibfk_9` FOREIGN KEY (`c_source`) REFERENCES `TEXT_CODES` (`c_textid`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `SOCIAL_INSTITUTION_NAME_CODES`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `SOCIAL_INSTITUTION_NAME_CODES` (
  `c_inst_name_code` smallint NOT NULL,
  `c_inst_name_hz` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_inst_name_py` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  PRIMARY KEY (`c_inst_name_code`) USING BTREE,
  KEY `c_inst_name_code_SOCIAL_INSTITUTION_NAME_CODES_index` (`c_inst_name_code`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `SOCIAL_INSTITUTION_TYPES`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `SOCIAL_INSTITUTION_TYPES` (
  `c_inst_type_code` smallint NOT NULL,
  `c_inst_type_py` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_inst_type_hz` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  PRIMARY KEY (`c_inst_type_code`) USING BTREE,
  KEY `c_inst_type_code_SOCIAL_INSTITUTION_TYPES_index` (`c_inst_type_code`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `STATUS_CODES`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `STATUS_CODES` (
  `c_status_code` smallint NOT NULL,
  `c_status_desc` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_status_desc_chn` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  PRIMARY KEY (`c_status_code`) USING BTREE,
  KEY `c_status_code_STATUS_CODES_index` (`c_status_code`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `STATUS_CODE_TYPE_REL`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `STATUS_CODE_TYPE_REL` (
  `c_status_code` smallint NOT NULL,
  `c_status_type_code` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  PRIMARY KEY (`c_status_code`,`c_status_type_code`) USING BTREE,
  KEY `c_status_code_STATUS_CODE_TYPE_REL_index` (`c_status_code`) USING BTREE,
  KEY `c_status_type_code_STATUS_CODE_TYPE_REL_index` (`c_status_type_code`(191)) USING BTREE,
  KEY `c_status_type_code` (`c_status_type_code`) USING BTREE,
  CONSTRAINT `STATUS_CODE_TYPE_REL_ibfk_1` FOREIGN KEY (`c_status_code`) REFERENCES `STATUS_CODES` (`c_status_code`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `STATUS_CODE_TYPE_REL_ibfk_2` FOREIGN KEY (`c_status_type_code`) REFERENCES `STATUS_TYPES` (`c_status_type_code`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `STATUS_DATA`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `STATUS_DATA` (
  `c_personid` int NOT NULL,
  `c_sequence` int NOT NULL,
  `c_status_code` smallint NOT NULL,
  `c_firstyear` smallint DEFAULT NULL,
  `c_fy_nh_code` smallint DEFAULT NULL,
  `c_fy_nh_year` smallint DEFAULT NULL,
  `c_fy_range` smallint DEFAULT NULL,
  `c_lastyear` smallint DEFAULT NULL,
  `c_ly_nh_code` smallint DEFAULT NULL,
  `c_ly_nh_year` smallint DEFAULT NULL,
  `c_ly_range` smallint DEFAULT NULL,
  `c_supplement` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_source` int DEFAULT NULL,
  `c_pages` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_notes` longtext COLLATE utf8mb4_general_ci,
  `c_created_by` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_created_date` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_modified_by` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_modified_date` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  PRIMARY KEY (`c_personid`,`c_sequence`,`c_status_code`) USING BTREE,
  KEY `c_personid_STATUS_DATA_index` (`c_personid`) USING BTREE,
  KEY `c_status_code_STATUS_DATA_index` (`c_status_code`) USING BTREE,
  KEY `c_fy_nh_code_STATUS_DATA_index` (`c_fy_nh_code`) USING BTREE,
  KEY `c_ly_nh_code_STATUS_DATA_index` (`c_ly_nh_code`) USING BTREE,
  KEY `c_fy_range` (`c_fy_range`),
  KEY `c_ly_range` (`c_ly_range`),
  KEY `c_source` (`c_source`),
  CONSTRAINT `STATUS_DATA_ibfk_1` FOREIGN KEY (`c_fy_nh_code`) REFERENCES `NIAN_HAO` (`c_nianhao_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `STATUS_DATA_ibfk_2` FOREIGN KEY (`c_fy_range`) REFERENCES `YEAR_RANGE_CODES` (`c_range_code`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `STATUS_DATA_ibfk_3` FOREIGN KEY (`c_ly_nh_code`) REFERENCES `NIAN_HAO` (`c_nianhao_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `STATUS_DATA_ibfk_4` FOREIGN KEY (`c_ly_range`) REFERENCES `YEAR_RANGE_CODES` (`c_range_code`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `STATUS_DATA_ibfk_5` FOREIGN KEY (`c_personid`) REFERENCES `BIOG_MAIN` (`c_personid`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `STATUS_DATA_ibfk_6` FOREIGN KEY (`c_source`) REFERENCES `TEXT_CODES` (`c_textid`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `STATUS_DATA_ibfk_7` FOREIGN KEY (`c_status_code`) REFERENCES `STATUS_CODES` (`c_status_code`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `STATUS_TYPES`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `STATUS_TYPES` (
  `c_status_type_code` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `c_status_type_desc` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_status_type_chn` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_status_type_parent_code` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  PRIMARY KEY (`c_status_type_code`) USING BTREE,
  KEY `c_status_type_code_STATUS_TYPES_index` (`c_status_type_code`(191)) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `TABLESFIELDS`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `TABLESFIELDS` (
  `RowNum` int DEFAULT NULL,
  `DumpTblNm` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `DumpFldNm` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `AccessTblNm` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `AccessFldNm` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `IndexOnField` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `DataFormat` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `NULL_allowed` smallint DEFAULT NULL,
  `ForeignKey` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `ForeignKeyBaseField` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `TABLESFIELDSCHANGES`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `TABLESFIELDSCHANGES` (
  `TableName` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `FieldName` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `Change` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `ChangeDate` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `ChangeNotes` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `TEXT_BIBLCAT_CODES`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `TEXT_BIBLCAT_CODES` (
  `c_text_cat_code` smallint NOT NULL,
  `c_text_cat_desc` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_text_cat_desc_chn` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_text_cat_pinyin` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_text_cat_parent_id` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_text_cat_level` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_text_cat_sortorder` smallint DEFAULT NULL,
  PRIMARY KEY (`c_text_cat_code`),
  KEY `c_text_cat_code_TEXT_BIBLCAT_CODES_index` (`c_text_cat_code`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `TEXT_BIBLCAT_CODE_TYPE_REL`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `TEXT_BIBLCAT_CODE_TYPE_REL` (
  `c_text_cat_code` smallint NOT NULL,
  `c_text_cat_type_id` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  PRIMARY KEY (`c_text_cat_code`,`c_text_cat_type_id`),
  KEY `c_text_cat_code_TEXT_BIBLCAT_CODE_TYPE_REL_index` (`c_text_cat_code`) USING BTREE,
  KEY `c_text_cat_type_id_TEXT_BIBLCAT_CODE_TYPE_REL_index` (`c_text_cat_type_id`(191)) USING BTREE,
  KEY `c_text_cat_type_id` (`c_text_cat_type_id`),
  CONSTRAINT `TEXT_BIBLCAT_CODE_TYPE_REL_ibfk_1` FOREIGN KEY (`c_text_cat_code`) REFERENCES `TEXT_BIBLCAT_CODES` (`c_text_cat_code`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `TEXT_BIBLCAT_CODE_TYPE_REL_ibfk_2` FOREIGN KEY (`c_text_cat_type_id`) REFERENCES `TEXT_BIBLCAT_TYPES` (`c_text_cat_type_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `TEXT_BIBLCAT_TYPES`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `TEXT_BIBLCAT_TYPES` (
  `c_text_cat_type_id` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `c_text_cat_type_desc` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_text_cat_type_desc_chn` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_text_cat_type_parent_id` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_text_cat_type_level` smallint DEFAULT NULL,
  `c_text_cat_type_sortorder` smallint DEFAULT NULL,
  PRIMARY KEY (`c_text_cat_type_id`),
  KEY `c_text_cat_type_id_TEXT_BIBLCAT_TYPES_index` (`c_text_cat_type_id`(191)) USING BTREE,
  KEY `c_text_cat_type_parent_id_TEXT_BIBLCAT_TYPES_index` (`c_text_cat_type_parent_id`(191)) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `TEXT_CODES`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `TEXT_CODES` (
  `c_textid` int NOT NULL,
  `c_title_chn` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_title` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_title_trans` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_text_type_id` varchar(128) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_text_year` smallint DEFAULT NULL,
  `c_text_nh_code` smallint DEFAULT NULL,
  `c_text_nh_year` smallint DEFAULT NULL,
  `c_text_range_code` smallint DEFAULT NULL,
  `c_bibl_cat_code` smallint DEFAULT '0',
  `c_extant` smallint DEFAULT NULL,
  `c_text_country` smallint DEFAULT NULL,
  `c_text_dy` smallint DEFAULT NULL,
  `c_source` int DEFAULT NULL,
  `c_pages` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_url_api` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_url_api_coda` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_url_homepage` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_notes` longtext COLLATE utf8mb4_general_ci,
  `c_title_alt_chn` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_created_by` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_created_date` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_modified_by` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_modified_date` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  PRIMARY KEY (`c_textid`) USING BTREE,
  KEY `c_textid_TEXT_CODES_index` (`c_textid`) USING BTREE,
  KEY `c_text_type_id_TEXT_CODES_index` (`c_text_type_id`) USING BTREE,
  KEY `c_text_nh_code_TEXT_CODES_index` (`c_text_nh_code`) USING BTREE,
  KEY `c_text_range_code_TEXT_CODES_index` (`c_text_range_code`) USING BTREE,
  KEY `c_bibl_cat_code_TEXT_CODES_index` (`c_bibl_cat_code`) USING BTREE,
  KEY `c_extant` (`c_extant`),
  KEY `c_source` (`c_source`),
  KEY `c_text_country` (`c_text_country`),
  KEY `c_text_dy` (`c_text_dy`),
  CONSTRAINT `TEXT_CODES_ibfk_1` FOREIGN KEY (`c_bibl_cat_code`) REFERENCES `TEXT_BIBLCAT_CODES` (`c_text_cat_code`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `TEXT_CODES_ibfk_10` FOREIGN KEY (`c_text_nh_code`) REFERENCES `NIAN_HAO` (`c_nianhao_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `TEXT_CODES_ibfk_11` FOREIGN KEY (`c_text_range_code`) REFERENCES `YEAR_RANGE_CODES` (`c_range_code`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `TEXT_CODES_ibfk_2` FOREIGN KEY (`c_extant`) REFERENCES `EXTANT_CODES` (`c_extant_code`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `TEXT_CODES_ibfk_7` FOREIGN KEY (`c_source`) REFERENCES `TEXT_CODES` (`c_textid`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `TEXT_CODES_ibfk_8` FOREIGN KEY (`c_text_country`) REFERENCES `COUNTRY_CODES` (`c_country_code`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `TEXT_CODES_ibfk_9` FOREIGN KEY (`c_text_dy`) REFERENCES `DYNASTIES` (`c_dy`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `TEXT_INSTANCE_DATA`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `TEXT_INSTANCE_DATA` (
  `c_textid` int NOT NULL,
  `c_text_edition_id` int NOT NULL,
  `c_text_instance_id` int NOT NULL,
  `c_instance_title_chn` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_instance_title` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_instance_title_trans` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_part_of_instance` int DEFAULT NULL,
  `c_part_of_instance_notes` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_pub_country` smallint DEFAULT NULL,
  `c_pub_dy` smallint DEFAULT NULL,
  `c_pub_year` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_pub_nh_code` smallint DEFAULT NULL,
  `c_pub_nh_year` smallint DEFAULT NULL,
  `c_pub_range_code` smallint DEFAULT NULL,
  `c_pub_loc` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_publisher` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_print` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_pub_notes` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_source` int DEFAULT NULL,
  `c_pages` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_extant` int DEFAULT NULL,
  `c_url_api` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_url_homepage` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_notes` longtext COLLATE utf8mb4_general_ci,
  `c_number` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_counter` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_title_alt_chn` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_created_by` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_created_date` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_modified_by` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_modified_date` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  PRIMARY KEY (`c_textid`,`c_text_edition_id`,`c_text_instance_id`) USING BTREE,
  KEY `c_textid_TEXT_CODES_index` (`c_textid`) USING BTREE,
  KEY `c_pub_nh_code_TEXT_CODES_index` (`c_pub_nh_code`) USING BTREE,
  KEY `c_pub_range_code_TEXT_CODES_index` (`c_pub_range_code`) USING BTREE,
  KEY `c_pub_country` (`c_pub_country`) USING BTREE,
  KEY `c_pub_dy` (`c_pub_dy`) USING BTREE,
  KEY `c_source` (`c_source`) USING BTREE,
  KEY `c_text_edition_id` (`c_text_edition_id`),
  KEY `c_text_instance_id` (`c_text_instance_id`),
  CONSTRAINT `TEXT_INSTANCE_DATA_ibfk_1` FOREIGN KEY (`c_textid`) REFERENCES `TEXT_CODES` (`c_textid`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `TEXT_INSTANCE_DATA_ibfk_2` FOREIGN KEY (`c_pub_nh_code`) REFERENCES `NIAN_HAO` (`c_nianhao_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `TEXT_INSTANCE_DATA_ibfk_3` FOREIGN KEY (`c_pub_country`) REFERENCES `COUNTRY_CODES` (`c_country_code`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `TEXT_INSTANCE_DATA_ibfk_4` FOREIGN KEY (`c_pub_dy`) REFERENCES `DYNASTIES` (`c_dy`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `TEXT_INSTANCE_DATA_ibfk_5` FOREIGN KEY (`c_pub_range_code`) REFERENCES `YEAR_RANGE_CODES` (`c_range_code`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `TEXT_INSTANCE_DATA_ibfk_6` FOREIGN KEY (`c_source`) REFERENCES `TEXT_CODES` (`c_textid`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `TEXT_ROLE_CODES`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `TEXT_ROLE_CODES` (
  `c_role_id` smallint NOT NULL,
  `c_role_desc` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_role_desc_chn` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  PRIMARY KEY (`c_role_id`) USING BTREE,
  KEY `c_role_id_TEXT_ROLE_CODES_index` (`c_role_id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `TEXT_TYPE`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `TEXT_TYPE` (
  `c_text_type_code` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `c_text_type_desc` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_text_type_desc_chn` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_text_type_parent_id` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_text_type_level` smallint DEFAULT NULL,
  `c_text_type_sortorder` smallint DEFAULT NULL,
  PRIMARY KEY (`c_text_type_code`) USING BTREE,
  KEY `c_text_type_code_TEXT_TYPE_index` (`c_text_type_code`(191)) USING BTREE,
  KEY `c_text_type_parent_id_TEXT_TYPE_index` (`c_text_type_parent_id`(191)) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `YEAR_RANGE_CODES`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `YEAR_RANGE_CODES` (
  `c_range_code` smallint NOT NULL,
  `c_range` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_range_chn` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_approx` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `c_approx_chn` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  PRIMARY KEY (`c_range_code`) USING BTREE,
  KEY `c_range_code_YEAR_RANGE_CODES_index` (`c_range_code`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `migrations`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `migrations` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `batch` int NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `oauth_access_tokens`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `oauth_access_tokens` (
  `id` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` int DEFAULT NULL,
  `client_id` int NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `scopes` text COLLATE utf8mb4_unicode_ci,
  `revoked` tinyint(1) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `oauth_access_tokens_user_id_index` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `oauth_auth_codes`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `oauth_auth_codes` (
  `id` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` int NOT NULL,
  `client_id` int NOT NULL,
  `scopes` text COLLATE utf8mb4_unicode_ci,
  `revoked` tinyint(1) NOT NULL,
  `expires_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `oauth_clients`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `oauth_clients` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int DEFAULT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `secret` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `redirect` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `personal_access_client` tinyint(1) NOT NULL,
  `password_client` tinyint(1) NOT NULL,
  `revoked` tinyint(1) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `oauth_clients_user_id_index` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `oauth_personal_access_clients`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `oauth_personal_access_clients` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `client_id` int NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `oauth_personal_access_clients_client_id_index` (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `oauth_refresh_tokens`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `oauth_refresh_tokens` (
  `id` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `access_token_id` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `revoked` tinyint(1) NOT NULL,
  `expires_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `oauth_refresh_tokens_access_token_id_index` (`access_token_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `operations`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `operations` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `c_personid` int NOT NULL,
  `op_type` smallint NOT NULL COMMENT '1.Popst(Create) 2.Put(Update 全部信息) 3. Patch(Update 部分属性) 4.Delete(Delete)',
  `resource` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `resource_id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `resource_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `resource_original` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `crowdsourcing_status` smallint NOT NULL DEFAULT '0',
  `rate` smallint NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `c_personid` (`c_personid`),
  CONSTRAINT `c_personid` FOREIGN KEY (`c_personid`) REFERENCES `BIOG_MAIN` (`c_personid`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=110 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `password_resets`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `password_resets` (
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  KEY `password_resets_email_index` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `pinyin`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pinyin` (
  `id` int NOT NULL AUTO_INCREMENT,
  `lastname_chn` varchar(10) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `lastname_pinyin` varchar(30) COLLATE utf8mb4_general_ci DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=525 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=COMPACT;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `users`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `password` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `institution` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `avatar` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'avatar5.png',
  `settings` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `confirmation_token` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_active` smallint NOT NULL DEFAULT '0' COMMENT '0 未验证， 2 激活邮件， 1 有编辑权限',
  `is_admin` smallint NOT NULL DEFAULT '0',
  `remember_token` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_email_unique` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2025-10-28 19:00:09
SQL;
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void {
        // Intentionally left blank. This migration serves as a historical baseline.
    }

    /**
     * @return array<int, string>
     */
    protected function extractCreateStatements(string $sql): array {
        $statements = [];
        $buffer = '';
        $length = strlen($sql);

        for ($i = 0; $i < $length; $i++) {
            $buffer .= $sql[$i];

            if ($sql[$i] === ';') {
                $trimmed = trim($buffer);
                $buffer = '';

                if ($trimmed === '') {
                    continue;
                }

                $normalized = ltrim($trimmed);
                $upper = strtoupper(substr($normalized, 0, 12));

                $skipPrefixes = [
                    '--',
                    '/*',
                    'LOCK TABLES',
                    'UNLOCK TABLES',
                    'DROP TABLE',
                    'SET ',
                    'INSERT INTO',
                    '/*!',
                ];

                $shouldSkip = false;
                foreach ($skipPrefixes as $prefix) {
                    if (stripos($normalized, $prefix) === 0) {
                        $shouldSkip = true;

                        break;
                    }
                }

                if ($shouldSkip) {
                    continue;
                }

                if (strpos($upper, 'CREATE TABLE') !== 0) {
                    continue;
                }

                if (!preg_match('/CREATE TABLE `([^`]+)`/i', $normalized, $matches)) {
                    continue;
                }

                $table = $matches[1];
                $statements[$table] = rtrim($normalized, ';');
            }
        }

        return $statements;
    }
}
