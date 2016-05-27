<?php
namespace mikehaertl\pdftk;

use mikehaertl\tmp\File;

/**
 * Pdf
 *
 * This class is a wrapper around pdftk.
 *
 * The class was developed for pdftk 2.x but should also work with older versions,
 * but you may have to use slightly different page rotation options (e.g 'E' instead 'east').
 *
 * @author Michael Härtl <haertl.mike@gmail.com>
 * @version 0.2.2
 * @license http://www.opensource.org/licenses/MIT
 */
class Pdf
{
    // The prefix for temporary files
    const TMP_PREFIX = 'tmp_php_pdftk_';

    /**
     * @var bool whether to ignore any errors if some non-empty output file was still created. Default is false.
     */
    public $ignoreWarnings = false;

    /**
     * @var mikehaertl\tmp\File the temporary output file
     */
    protected $_tmpFile;

    /**
     * @var string the content type of the tmp output
     */
    protected $_tmpOutputContentType = 'application/pdf';

    /**
     * @var Command the command instance that executes pdftk
     */
    protected $_command;

    /**
     * @var int a counter for autogenerated handles
     */
    protected $_handle = 0;

    /**
     * @var string the error message
     */
    protected $_error = '';

    /**
     * @var string|null the output filename. If null (default) a tmp file is used as output. If false,
     * no output option is added at all.
     */
    protected $_output;

    /**
     * @var string the PDF data as returned from getData()
     */
    protected $_data;
    protected $_data_utf8;

    /**
     * @var string the PDF form field data as returned from getDataFields()
     */
    protected $_dataFields;
    protected $_dataFields_utf8;

    /**
     * @var Pdf|null if the input was an instance, we keep a reference here, so that it won't get
     * unlinked before this object gets destroyed
     */
    protected $_pdf;

    /**
     * @param string|Pdf|array $pdf a pdf filename or Pdf instance or an array of filenames/instances indexed by a handle.
     * The array values can also be arrays of the form array($filename, $password) if some
     * files are password protected.
     * @param array $options Options to pass to set on the Command instance, e.g. the pdftk binary path
     */
    public function __construct($pdf = null, $options = array())
    {
        $command = $this->getCommand();
        if ($options!==array()) {
            $command->setOptions($options);
        }
        if (is_string($pdf) || $pdf instanceof Pdf) {
            $this->addFile($pdf);
        } elseif (is_array($pdf)) {
            foreach ($pdf as $handle => $file) {
                if (is_array($file)) {
                    $this->addFile($file[0], $handle, $file[1]);
                } else {
                    $this->addFile($file, $handle);
                }
            }
        }
    }

    /**
     * @param string|Pdf $name the PDF filename or Pdf instance to add for processing
     * @param string|null $handle one or more uppercase letters A..Z to reference this file later.
     * If no handle is provided, an internal handle is autocreated, consuming the range Z..A
     * @param string|null $password the owner (or user) password if any
     * @return Pdf the pdf instance for method chaining
     */
    public function addFile($name, $handle = null, $password = null)
    {
        if ($handle===null) {
            $handle = $this->nextHandle();
        }
        if ($name instanceof Pdf) {
            // Keep a reference to the object to prevent unlinking
            $this->_pdf = $name;
            if (!$name->getCommand()->getExecuted()) {
                // @todo: Catch errors!
                $name->execute();
            }
            $name = (string) $name->getTmpFile();
        }
        $this->getCommand()->addFile($name, $handle, $password);
        return $this;
    }

    /**
     * Assemble (catenate) pages from the input files.
     *
     * Values for rotation are (in degrees): north: 0, east: 90, south: 180, west: 270, left: -90,
     * right: +90, down: +180. left, right and down make relative adjustments to a page's rotation.
     * Note: Older pdftk versions use N, E, S, W, L, R, and D instead.
     *
     * Example:
     *
     *  $pdf = new Pdf;
     *  $pdf->addFile('file1.pdf', 'A')
     *      ->addFile('file2.pdf', 'B')
     *      ->cat(array(1,3),'B'))          // pages 1 and 3 of file B
     *      ->cat(1, 5, 'A', 'odd')         // pages 1, 3, 5 of file A
     *      ->cat('end', 5, 'B')            // pages 5 to end of file B in reverse order
     *      ->cat(null, null, 'B', 'east')  // All pages from file B rotated by 90 degree
     *      ->saveAs('out.pdf');
     *
     * @param int|string|array $start the start page number or an array of page numbers. If an array, the other
     * arguments will be ignored. $start can also be bigger than $end for pages in reverse order.
     * @param int|string|null $end the end page number or null for single page (or list if $start is an array)
     * @param string|null $handle the handle of the file to use. Can be null if only a single file was added.
     * @param string|null $qualifier the page number qualifier, either 'even' or 'odd' or null for none
     * @param string $rotation the rotation to apply to the pages.
     * @return Pdf the pdf instance for method chaining
     */
    public function cat($start, $end = null, $handle = null, $qualifier = null, $rotation = null)
    {
        $this->getCommand()
            ->setOperation('cat')
            ->addPageRange($start, $end, $handle, $qualifier, $rotation);
        return $this;
    }

    /**
     * Shuffle pages from the input files.
     *
     * This works the same as cat(), but each call to this method creates a "stream" of pages. The outfile
     * will be assembled by adding one page from each stream at a time.
     *
     * Example:
     *
     *  $pdf = new Pdf;
     *  $pdf1 = $pdf->addFile('file1.pdf');
     *  $pdf->cat($pdf1, array(1,3,2))
     *      ->cat($pdf1, array(4,5,9)
     *      ->saveAs('out.pdf');
     *
     *  This will give the page order 1, 4, 3, 5, 2, 9 in the out.pdf
     *
     * @param string $handle the handle of the input file to use
     * @param int|array $start the start page number or an array of page numbers.
     * @param int|null $end the end page number or null for single page (or list if $start is an array)
     * @param string|null $qualifier the page number qualifier, either 'even' or 'odd' or null for none
     * @param string $rotation the rotation to apply to the pages. See cat() for more details.
     * @return Pdf the pdf instance for method chaining
     */
    public function shuffle($start, $end = null, $handle = null, $qualifier = null, $rotation = null)
    {
        $this->getCommand()
            ->setOperation('shuffle')
            ->addPageRange($start, $end, $handle, $qualifier, $rotation);
        return $this;
    }

    /**
     * Split the PDF document into pages
     *
     * @param string|null $filepattern the output name in sprintf format or null for default 'pg_%04d.pdf'
     * @return bool whether the burst command was successful
     * @return bool whether the burst operation was successful
     */
    public function burst($filepattern = null)
    {
        $this->constrainSingleFile();
        $this->getCommand()->setOperation('burst');
        $this->_output = $filepattern===null ? 'pg_%04d.pdf' : $filepattern;
        return $this->execute();
    }

    /**
     * Generate the FDF file for a single PDF file.
     *
     * @param string $name name of the FDF file
     * @return Pdf the pdf instance for method chaining
     */
    public function generateFdfFile($name)
    {
        $this->constrainSingleFile();
        $this->getCommand()->setOperation('generate_fdf');
        $this->_output = $name;
        return $this->execute();
    }

    /**
     * Fill a PDF form
     *
     * @param string|array $data either a FDF filename or an array with form field data (name => value)
     * @param string the encoding of the data. Default is 'UTF-8'.
     * @param bool whether to drop XFA forms (see dropXfa()). Default is true.
     * @return Pdf the pdf instance for method chaining
     */
    public function fillForm($data, $encoding = 'UTF-8', $dropXfa = true)
    {
        $this->constrainSingleFile();
        $this->getCommand()
            ->setOperation('fill_form')
            ->setOperationArgument(is_array($data) ? new XfdfFile($data, null, null, null, $encoding) : $data, true);
        if ($dropXfa) {
            $this->dropXfa();
        }
        return $this;
    }

    /**
     * Apply a PDF as watermark to the background of a single PDF file.
     *
     * The PDF file must have a transparent background for the watermark to be visible.
     *
     * @param string $file name of the background PDF file. Only the first page is used.
     * @return Pdf the pdf instance for method chaining
     */
    public function background($file)
    {
        $this->constrainSingleFile();
        $this->getCommand()
            ->setOperation('background')
            ->setOperationArgument($file, true);
        return $this;
    }

    /**
     * Apply multiple PDF pages as watermark to the corresponding pages of a single PDF file.
     *
     * If $file has fewer pages than the PDF file then the last page is repeated as background.
     *
     * @param string $file name of the background PDF file.
     * @return Pdf the pdf instance for method chaining
     */
    public function multiBackground($file)
    {
        $this->getCommand()
            ->setOperation('multibackground')
            ->setOperationArgument($file, true);
        return $this;
    }

    /**
     * Add $file as overlay to a single PDF file.
     *
     * The $file should have a transparent background.
     *
     * @param string $file name of the PDF file to add as overlay. Only the first page is used.
     * @return Pdf the pdf instance for method chaining
     */
    public function stamp($file)
    {
        $this->constrainSingleFile();
        $this->getCommand()
            ->setOperation('stamp')
            ->setOperationArgument($file, true);
        return $this;
    }

    /**
     * Add multiple pages from $file as overlay to the corresponding pages of a single PDF file.
     *
     * If $file has fewer pages than the PDF file then the last page is repeated as overlay.
     *
     * @param string $file name of the PDF file to add as overlay
     * @return Pdf the pdf instance for method chaining
     */
    public function multiStamp($file)
    {
        $this->getCommand()
            ->setOperation('multistamp')
            ->setOperationArgument($file, true);
        return $this;
    }

    /**
     * @param bool $utf8 whether to dump the data UTF-8 encoded. Default is true.
     * @return string|bool meta data about the PDF or false on failure
     */
    public function getData($utf8 = true)
    {
        $property = $utf8 ? '_data_utf8' : '_data';
        if ($this->$property===null) {
            $command = $this->getCommand();
            $command->setOperation($utf8 ? 'dump_data_utf8' : 'dump_data');
            if (!$command->execute()) {
                return false;
            } else {
                $this->$property = trim($command->getOutput());
            }
        }
        return $this->$property;
    }

    /**
     * @param bool $utf8 whether to dump the data UTF-8 encoded. Default is true.
     * @return string|bool data about the PDF form fields or false on failure
     */
    public function getDataFields($utf8 = true)
    {
        $property = $utf8 ? '_dataFields_utf8' : '_dataFields';
        if ($this->$property===null) {
            $command = $this->getCommand();
            $command->setOperation($utf8 ? 'dump_data_fields_utf8' : 'dump_data_fields');
            if (!$command->execute()) {
                return false;
            } else {
                $this->$property = trim($command->getOutput());
            }
        }
        return $this->$property;
    }

    /**
     * Set PDF permissions
     *
     * The available permissions are Printing, DegradedPrinting, ModifyContents, Assembly,
     * CopyContents, ScreenReaders, ModifyAnnotations, FillIn, AllFeatures.
     * @param string|null $permissions list of space separated permissions or null for none.
     * @return Pdf the pdf instance for method chaining
     */
    public function allow($permissions = null)
    {
        $this->getCommand()
            ->addOption('allow', $permissions, false);
        return $this;
    }

    /**
     * Flatten the PDF form fields values into a single PDF file.
     *
     * @return Pdf the pdf instance for method chaining
     */
    public function flatten()
    {
        $this->getCommand()
            ->addOption('flatten');
        return $this;
    }

    /**
     * Restore/remove compression
     *
     * @param bool $compress whether to restore (default) or remove the compression
     * @return Pdf the pdf instance for method chaining
     */
    public function compress($compress = true)
    {
        $this->getCommand()
            ->addOption($compress ? 'compress' : 'uncompress');
        return $this;
    }

    /**
     * When combining multiple PDFs, use either the first or last ID in the output.
     * If not called, a new ID is created.
     *
     * @param string $id, either 'first' (default) or 'last'
     * @return Pdf the pdf instance for method chaining
     */
    public function keepId($id = 'first')
    {
        $this->getCommand()
            ->addOption($id==='first' ? 'keep_first_id' : 'keep_final_id');
        return $this;
    }

    /**
     * Set need_appearances flag in PDF
     *
     * This flag makes sure, that a PDF reader takes care of rendering form field content, even
     * if it contains non ASCII characters. You should always use this option if you fill in forms
     * e.g. with Unicode characters. You can't combine this option with flatten() though!
     *
     * @return Pdf the pdf instance for method chaining
     */
    public function needAppearances()
    {
        $this->getCommand()
            ->addOption('need_appearances');
        return $this;
    }

    /**
     * Drop XFA data from forms created with newer Acrobat.
     *
     * Newer PDF forms contain both, the newer XFA and the older AcroForm form fields. PDF readers
     * can use both, but will prefer XFA if present. Since pdftk can only fill in AcroForm data you
     * should always add this option when filling in forms with pdftk.
     *
     * @return Pdf the pdf instance for method chaining
     */
    public function dropXfa()
    {
        $this->getCommand()
            ->addOption('drop_xfa');
        return $this;
    }

    /**
     * Drop XMP meta data
     *
     * Newer PDFs can contain both, new style XMP data and old style info directory. PDF readers
     * can use both, but will prefer XMP if present. Since pdftk can only update the info directory
     * you should always add this option when updating PDF info.
     *
     * @return Pdf the pdf instance for method chaining
     */
    public function dropXmp()
    {
        $this->getCommand()
            ->addOption('drop_xmp');
        return $this;
    }

    /**
     * @param string $password the owner password to set on the output PDF
     * @return Pdf the pdf instance for method chaining
     */
    public function setPassword($password)
    {
        $this->getCommand()
            ->addOption('owner_pw', $password, true);
        return $this;
    }

    /**
     * @param string $password the user password to set on the output PDF
     * @return Pdf the pdf instance for method chaining
     */
    public function setUserPassword($password)
    {
        $this->getCommand()
            ->addOption('user_pw', $password, true);
        return $this;
    }

    /**
     * @param int $strength the password encryption strength. Default is 128
     * @return Pdf the pdf instance for method chaining
     */
    public function passwordEncryption($strength = 128)
    {
        $this->getCommand()
            ->addOption($strength==128 ? 'encrypt_128bit' : 'encrypt_40bit');
        return $this;
    }

    /**
     * Execute the operation and save the output file
     *
     * @param string $name of output file
     * @return bool whether the PDF could be processed and saved
     */
    public function saveAs($name)
    {
        if (!$this->getCommand()->getExecuted() && !$this->execute()) {
            return false;
        }
        $tmpFile = (string) $this->getTmpFile();
        if (!copy($tmpFile, $name)) {
            $this->_error = "Could not copy PDF from tmp location '$tmpFile' to '$name'";
            return false;
        }
        return true;
    }

    /**
     * Send PDF to client, either inline or as download (triggers PDF creation)
     *
     * @param string|null $filename the filename to send. If empty, the PDF is streamed inline.
     * @param bool $inline whether to force inline display of the PDF, even if filename is present.
     * @return bool whether PDF was created successfully
     */
    public function send($filename=null,$inline=false)
    {
        if (!$this->getCommand()->getExecuted() && !$this->execute()) {
            return false;
        }
        $this->getTmpFile()->send($filename, $this->_tmpOutputContentType, $inline);
        return true;
    }

    /**
     * @return Command the command instance that executes pdftk
     */
    public function getCommand()
    {
        if ($this->_command===null) {
            $this->_command = new Command;
        }
        return $this->_command;
    }

    /**
     * @return mikehaertl\tmp\File the temporary output file instance
     */
    public function getTmpFile()
    {
        if ($this->_tmpFile===null) {
            $this->_tmpFile = new File('', '.pdf', self::TMP_PREFIX);
        }
        return $this->_tmpFile;
    }

    /**
     * @return string the error message or an empty string if none
     */
    public function getError()
    {
        return $this->_error;
    }

    /**
     * Execute the pdftk command and store the output file to a temporary location or $this->_output if set.
     * You should probably never call this method unless you only need a temporary PDF file as result.
     * @return bool whether the command was executed successfully
     */
    public function execute()
    {
        $command = $this->getCommand();
        if ($command->getExecuted()) {
            return false;
        }

        if ($this->_output===false) {
            $filename = null;
        } else {
            $filename = $this->_output ? $this->_output : (string) $this->getTmpFile();
        }
        if (!$command->execute($filename)) {
            $this->_error = $command->getError();
            if ($filename && !(file_exists($filename) && filesize($filename)!==0 && $this->ignoreWarnings)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Make sure, that only one file is present
     */
    protected function constrainSingleFile()
    {
        if ($this->getCommand()->getFileCount()>1) {
            throw new \Exception('This operation can only process single files');
        }
    }

    /**
     * @return string the next handle in the series A, B, C, ... Z, AA, AB...
     */
    protected function nextHandle()
    {
        // N.B. Multi-character handles are only available in pdftk 1.45+

        $i = $this->_handle++;
        $char = 'A';
        while ($i-- > 0) {
            $char++;
        }

        return $char;
    }
}
