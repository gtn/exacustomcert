<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Class represents a customcert template.
 *
 * @package    mod_customcert
 * @copyright  2016 Mark Nelson <markn@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_customcert;

defined('MOODLE_INTERNAL') || die();

/**
 * Class represents a customcert template.
 *
 * @package    mod_customcert
 * @copyright  2016 Mark Nelson <markn@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class template {

    /**
     * @var int $id The id of the template.
     */
    protected $id;

    /**
     * @var string $name The name of this template
     */
    protected $name;

    /**
     * @var int $contextid The context id of this template
     */
    protected $contextid;

    /**
     * The constructor.
     *
     * @param \stdClass $template
     */
    public function __construct($template) {
        $this->id = $template->id;
        $this->name = $template->name;
        $this->contextid = $template->contextid;
    }

    /**
     * Handles saving data.
     *
     * @param \stdClass $data the template data
     */
    public function save($data) {
        global $DB;

        $savedata = new \stdClass();
        $savedata->id = $this->id;
        $savedata->name = $data->name;
        $savedata->timemodified = time();

        $DB->update_record('customcert_templates', $savedata);
    }

    /**
     * Handles adding another page to the template.
     *
     * @return int the id of the page
     */
    public function add_page() {
        global $DB;

        // Set the page number to 1 to begin with.
        $sequence = 1;
        // Get the max page number.
        $sql = "SELECT MAX(sequence) as maxpage
                  FROM {customcert_pages} cp
                 WHERE cp.templateid = :templateid";
        if ($maxpage = $DB->get_record_sql($sql, array('templateid' => $this->id))) {
            $sequence = $maxpage->maxpage + 1;
        }

        // New page creation.
        $page = new \stdClass();
        $page->templateid = $this->id;
        $page->width = '210';
        $page->height = '297';
        $page->sequence = $sequence;
        $page->timecreated = time();
        $page->timemodified = $page->timecreated;

        // Insert the page.
        return $DB->insert_record('customcert_pages', $page);
    }

    /**
     * Handles saving page data.
     *
     * @param \stdClass $data the template data
     */
    public function save_page($data) {
        global $DB;

        // Set the time to a variable.
        $time = time();

        // Get the existing pages and save the page data.
        if ($pages = $DB->get_records('customcert_pages', array('templateid' => $data->tid))) {
            // Loop through existing pages.
            foreach ($pages as $page) {
                // Get the name of the fields we want from the form.
                $width = 'pagewidth_' . $page->id;
                $height = 'pageheight_' . $page->id;
                $leftmargin = 'pageleftmargin_' . $page->id;
                $rightmargin = 'pagerightmargin_' . $page->id;
                // Create the page data to update the DB with.
                $p = new \stdClass();
                $p->id = $page->id;
                $p->width = $data->$width;
                $p->height = $data->$height;
                $p->leftmargin = $data->$leftmargin;
                $p->rightmargin = $data->$rightmargin;
                $p->timemodified = $time;
                // Update the page.
                $DB->update_record('customcert_pages', $p);
            }
        }
    }

    /**
     * Handles deleting the template.
     *
     * @return bool return true if the deletion was successful, false otherwise
     */
    public function delete() {
        global $DB;

        // Delete the elements.
        $sql = "SELECT e.*
                  FROM {customcert_elements} e
            INNER JOIN {customcert_pages} p
                    ON e.pageid = p.id
                 WHERE p.templateid = :templateid";
        if ($elements = $DB->get_records_sql($sql, array('templateid' => $this->id))) {
            foreach ($elements as $element) {
                // Get an instance of the element class.
                if ($e = \mod_customcert\element_factory::get_element_instance($element)) {
                    $e->delete();
                } else {
                    // The plugin files are missing, so just remove the entry from the DB.
                    $DB->delete_records('customcert_elements', array('id' => $element->id));
                }
            }
        }

        // Delete the pages.
        if (!$DB->delete_records('customcert_pages', array('templateid' => $this->id))) {
            return false;
        }

        // Now, finally delete the actual template.
        if (!$DB->delete_records('customcert_templates', array('id' => $this->id))) {
            return false;
        }

        return true;
    }

    /**
     * Handles deleting a page from the template.
     *
     * @param int $pageid the template page
     */
    public function delete_page($pageid) {
        global $DB;

        // Get the page.
        $page = $DB->get_record('customcert_pages', array('id' => $pageid), '*', MUST_EXIST);

        // Delete this page.
        $DB->delete_records('customcert_pages', array('id' => $page->id));

        // The element may have some extra tasks it needs to complete to completely delete itself.
        if ($elements = $DB->get_records('customcert_elements', array('pageid' => $page->id))) {
            foreach ($elements as $element) {
                // Get an instance of the element class.
                if ($e = \mod_customcert\element_factory::get_element_instance($element)) {
                    $e->delete();
                } else {
                    // The plugin files are missing, so just remove the entry from the DB.
                    $DB->delete_records('customcert_elements', array('id' => $element->id));
                }
            }
        }

        // Now we want to decrease the page number values of
        // the pages that are greater than the page we deleted.
        $sql = "UPDATE {customcert_pages}
                   SET sequence = sequence - 1
                 WHERE templateid = :templateid
                   AND sequence > :sequence";
        $DB->execute($sql, array('templateid' => $this->id, 'sequence' => $page->sequence));
    }

    /**
     * Handles deleting an element from the template.
     *
     * @param int $elementid the template page
     */
    public function delete_element($elementid) {
        global $DB;

        // Ensure element exists and delete it.
        $element = $DB->get_record('customcert_elements', array('id' => $elementid), '*', MUST_EXIST);

        // Get an instance of the element class.
        if ($e = \mod_customcert\element_factory::get_element_instance($element)) {
            $e->delete();
        } else {
            // The plugin files are missing, so just remove the entry from the DB.
            $DB->delete_records('customcert_elements', array('id' => $elementid));
        }

        // Now we want to decrease the sequence numbers of the elements
        // that are greater than the element we deleted.
        $sql = "UPDATE {customcert_elements}
                   SET sequence = sequence - 1
                 WHERE pageid = :pageid
                   AND sequence > :sequence";
        $DB->execute($sql, array('pageid' => $element->pageid, 'sequence' => $element->sequence));
    }

    /**
     * Generate the PDF for the template.
     *
     * @param bool $preview true if it is a preview, false otherwise
     * @param int $userid the id of the user whose certificate we want to view
     * @param bool $return Do we want to return the contents of the PDF?
     * @return string|void Can return the PDF in string format if specified.
     */
    public function generate_pdf(bool $preview = false, int $userid = null, bool $return = false) {
        global $CFG, $DB, $USER, $COURSE;

        if (empty($userid)) {
            $user = $USER;
        } else {
            $user = \core_user::get_user($userid);
        }

        require_once($CFG->dirroot . '/lib/tcpdf/tcpdf.php');

        $cm = $this->get_cm();


        $pdf = new \TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        // set default monospaced font
        $pdf->SetDefaultMonospacedFont(PDF_FONT_MONOSPACED);

        // set margins
        $pdf->SetMargins(PDF_MARGIN_LEFT, PDF_MARGIN_TOP + 42, PDF_MARGIN_RIGHT);
        $pdf->SetHeaderMargin(0);
        $pdf->SetFooterMargin(0);

        // remove default footer
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);

        // set image scale factor
        $pdf->setImageScale(PDF_IMAGE_SCALE_RATIO);

        // set font

        $pdf->SetTextColor(0, 0, 0);
        // add a page
        $pdf->AddPage();

        // get the current page break margin
        $bMargin = $pdf->getBreakMargin();
        // get current auto-page-break mode
        $auto_page_break = $pdf->getAutoPageBreak();
        // disable auto-page-break
        $pdf->SetAutoPageBreak(false, 0);
        // set bacground image

        $logo = $CFG->dirroot . '/mod/customcert/pix/netLogo.png';
        $pdf->Image($logo, 75, 10, 58, 41, 'PNG', '', 'C', false, 300, 'C', false, false, 0);
        $sign = $CFG->dirroot . '/mod/customcert/pix/sign.png';
        $pdf->Image($sign, 20, 215, 0, 0, 'PNG', '', 'C', false, 300, '', false, false, 0);
        $footer = $CFG->dirroot . '/mod/customcert/pix/footer.png';
        $pdf->Image($footer, 18, 260, 0, 0, 'PNG', '', 'C', false, 300, '', false, false, 0);

        $pdf->SetAutoPageBreak($auto_page_break, $bMargin);
        // set the starting point for the page content
        $pdf->setPageMark();

        $pdf->Line(25, 60, 185, 60);

        //$pdf->SetXY(65, 70);
        $pdf->SetFont('helvetica', 'B', 22);

        $pdf->Write(0, "Teilnahmebestätigung", '', 0, 'C', true, 0, false, false, 0);

        $pdf->Ln();
        $pdf->SetFont('helvetica', '', 18);
        $pdf->Write(0, $USER->firstname . ' '. $USER->lastname, '', 0, 'C', true, 0, false, false, 0);
        $pdf->SetFont('helvetica', '', 12);
        $pdf->Ln();

        $sql = "SELECT uid.data
			FROM {user_info_data} uid
			JOIN {user_info_field} uif ON uid.fieldid = uif.id
			WHERE uif.shortname = 'birthday'";

        $birthday =$DB->get_records_sql($sql, array());

        foreach( $birthday as $birth){
            $birthday = $birth->data;
        }

        $pdf->Write(0, "geboren am ". date("d.m.Y", intval($birthday)), '', 0, 'C', true, 0, false, false, 0);
        $pdf->Ln();
        $pdf->Write(0, "hat in Lehrgang", '', 0, 'C', true, 0, false, false, 0);
        $pdf->Ln();
        $pdf->Write(0, $COURSE->fullname, '', 0, 'C', true, 0, false, false, 0);
        $pdf->Ln();
        $pdf->Write(0, "am Seminar", '', 0, 'C', true, 0, false, false, 0);
        $pdf->Ln();
        $pdf->SetFont('helvetica', 'B', 18);

        $topic = $DB->get_record('course_sections', array('id'=> $cm->section));
        $pdf->Write(10, $topic->name, '', 0, 'C', true, 0, false, false, 0);
        $pdf->SetFont('helvetica', '', 12);

        $summaryRaw = $topic->summary;
        $summaryData = explode(":", $summaryRaw);
        substr($summaryData[2], 0, strpos($summaryData, "</p><p"));


        $pdf->Write(0, substr($summaryData[2], 0, strpos($summaryData[2], "</p><p")), '', 0, 'C', true, 0, false, false, 0);
        $pdf->Ln();
        $pdf->Ln();
        $pdf->Write(0, "ReferentInnen: " . substr($summaryData[4], 0, strpos($summaryData[4], "</p>")), '', 0, 'C', true, 0, false, false, 0);
        $pdf->Ln();
        $pdf->Ln();
        $pdf->Write(0, "teilgenommen.", '', 0, 'C', true, 0, false, false, 0);
        $pdf->Ln();
        $pdf->Ln();
        $pdf->Ln();
        $pdf->Ln();
        $pdf->Ln();
        $pdf->Ln();
        $pdf->Ln();
        $pdf->Ln();
        $pdf->Ln();
        $pdf->Ln();

        $pdf->Write(0, "Linz, am ".date("d.m.Y"), '', 0, 'C', true, 0, false, false, 0);


        $pdf->Output('Teilnahmebestätigung.pdf', 'I');

    }

    /**
     * Handles copying this template into another.
     *
     * @param int $copytotemplateid The template id to copy to
     */
    public function copy_to_template($copytotemplateid) {
        global $DB;

        // Get the pages for the template, there should always be at least one page for each template.
        if ($templatepages = $DB->get_records('customcert_pages', array('templateid' => $this->id))) {
            // Loop through the pages.
            foreach ($templatepages as $templatepage) {
                $page = clone($templatepage);
                $page->templateid = $copytotemplateid;
                $page->timecreated = time();
                $page->timemodified = $page->timecreated;
                // Insert into the database.
                $page->id = $DB->insert_record('customcert_pages', $page);
                // Now go through the elements we want to load.
                if ($templateelements = $DB->get_records('customcert_elements', array('pageid' => $templatepage->id))) {
                    foreach ($templateelements as $templateelement) {
                        $element = clone($templateelement);
                        $element->pageid = $page->id;
                        $element->timecreated = time();
                        $element->timemodified = $element->timecreated;
                        // Ok, now we want to insert this into the database.
                        $element->id = $DB->insert_record('customcert_elements', $element);
                        // Load any other information the element may need to for the template.
                        if ($e = \mod_customcert\element_factory::get_element_instance($element)) {
                            if (!$e->copy_element($templateelement)) {
                                // Failed to copy - delete the element.
                                $e->delete();
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     * Handles moving an item on a template.
     *
     * @param string $itemname the item we are moving
     * @param int $itemid the id of the item
     * @param string $direction the direction
     */
    public function move_item($itemname, $itemid, $direction) {
        global $DB;

        $table = 'customcert_';
        if ($itemname == 'page') {
            $table .= 'pages';
        } else { // Must be an element.
            $table .= 'elements';
        }

        if ($moveitem = $DB->get_record($table, array('id' => $itemid))) {
            // Check which direction we are going.
            if ($direction == 'up') {
                $sequence = $moveitem->sequence - 1;
            } else { // Must be down.
                $sequence = $moveitem->sequence + 1;
            }

            // Get the item we will be swapping with. Make sure it is related to the same template (if it's
            // a page) or the same page (if it's an element).
            if ($itemname == 'page') {
                $params = array('templateid' => $moveitem->templateid);
            } else { // Must be an element.
                $params = array('pageid' => $moveitem->pageid);
            }
            $swapitem = $DB->get_record($table, $params + array('sequence' => $sequence));
        }

        // Check that there is an item to move, and an item to swap it with.
        if ($moveitem && !empty($swapitem)) {
            $DB->set_field($table, 'sequence', $swapitem->sequence, array('id' => $moveitem->id));
            $DB->set_field($table, 'sequence', $moveitem->sequence, array('id' => $swapitem->id));
        }
    }

    /**
     * Returns the id of the template.
     *
     * @return int the id of the template
     */
    public function get_id() {
        return $this->id;
    }

    /**
     * Returns the name of the template.
     *
     * @return string the name of the template
     */
    public function get_name() {
        return $this->name;
    }

    /**
     * Returns the context id.
     *
     * @return int the context id
     */
    public function get_contextid() {
        return $this->contextid;
    }

    /**
     * Returns the context id.
     *
     * @return \context the context
     */
    public function get_context() {
        return \context::instance_by_id($this->contextid);
    }

    /**
     * Returns the context id.
     *
     * @return \context_module|null the context module, null if there is none
     */
    public function get_cm() {
        $context = $this->get_context();
        if ($context->contextlevel === CONTEXT_MODULE) {
            return get_coursemodule_from_id('customcert', $context->instanceid, 0, false, MUST_EXIST);
        }

        return null;
    }

    /**
     * Ensures the user has the proper capabilities to manage this template.
     *
     * @throws \required_capability_exception if the user does not have the necessary capabilities (ie. Fred)
     */
    public function require_manage() {
        require_capability('mod/customcert:manage', $this->get_context());
    }

    /**
     * Creates a template.
     *
     * @param string $templatename the name of the template
     * @param int $contextid the context id
     * @return \mod_customcert\template the template object
     */
    public static function create($templatename, $contextid) {
        global $DB;

        $template = new \stdClass();
        $template->name = $templatename;
        $template->contextid = $contextid;
        $template->timecreated = time();
        $template->timemodified = $template->timecreated;
        $template->id = $DB->insert_record('customcert_templates', $template);

        return new \mod_customcert\template($template);
    }
}
