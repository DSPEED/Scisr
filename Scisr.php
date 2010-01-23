<?php
/*
 * Copyright (C) 2009, 2010 Ian Young
 * 
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 * 
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 * 
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

// Turn on error reporting
error_reporting(E_ALL | E_STRICT);
// Register our autoloader
spl_autoload_register('scisrAutoload');
// Include the main CodeSniffer file (this will register its own autoloader as well)
require_once(dirname(__FILE__) . '/PHP/CodeSniffer.php');

/**
 * An autoload function for Scisr
 * @param string
 */
function scisrAutoload($className)
{
    if (strpos($className, 'Scisr_') === 0) {
        $className = substr($className, 6);
        $path = str_replace('_', '/', $className).'.php';
        if (is_file(dirname(__FILE__).'/'.$path) === true) {
            include dirname(__FILE__).'/'.$path;
        }
    }
}

/**
 * Handles the job of finding and parsing files.
 */
class Scisr_CodeSniffer extends PHP_CodeSniffer
{

    public function __construct($verbosity=0, $tabWidth=0)
    {
        // PHP_CodeSniffer messes up the cwd, so restore it after we construct
        $cwd = getcwd();
        parent::__construct($verbosity, $tabWidth);
        chdir($cwd);
    }

    /**
     * Add a listener
     * @param PHP_CodeSniffer_Sniff the listener to add. Unlike
     * PHP_CodeSniffer's methods, this one takes an instantiated object.
     */
    public function addListener(PHP_CodeSniffer_Sniff $listener)
    {
        $this->listeners[] = $listener;
    }
}

/**
 * The main Scisr controller
 *
 * Coordinates the tasks to take place for the given action(s)
 */
class Scisr
{

    const MODE_TIMID = 0;
    const MODE_CONSERVATIVE = 1;
    const MODE_AGGRESSIVE = 2;

    /**
     * CodeSniffer listener objects to be used during the first, read-only pass
     * @var array
     */
    protected $_firstPassListeners = array();
    /**
     * CodeSniffer listener objects to be used during the main processing pass
     * @var array
     */
    protected $_listeners = array();
    /**
     * The output object to handle our messages
     * @var Scisr_Output
     */
    protected $_output;
    /**
     * Our codesniffer instance
     * @var Scisr_CodeSniffer
     */
    private $sniffer;

    public function __construct($output=null)
    {
        $this->setEditMode(self::MODE_CONSERVATIVE);
        if ($output === null) {
            $output = new Scisr_NullOutput();
        }
        $this->_output = $output;
        $this->sniffer = new Scisr_CodeSniffer();
    }

    /**
     * For testing use only. Dependency injection.
     * @ignore
     * @param Scisr_CodeSniffer
     */
    public function setSniffer($sniffer)
    {
        $this->sniffer = $sniffer;
    }

    /**
     * Rename a class
     * @param string $oldClass the class to be renamed
     * @param string $newClass the new class name to be given
     */
    public function setRenameClass($oldClass, $newClass)
    {
        $this->_listeners[] = new Scisr_Operations_RenameClass($oldClass, $newClass);
        $this->_listeners[] = new Scisr_Operations_ChangeClassNameComments($oldClass, $newClass);
        $this->_listeners[] = new Scisr_Operations_ChangeCommentWords($oldClass, $newClass);
        $this->_listeners[] = new Scisr_Operations_ChangeStringWords($oldClass, $newClass);
    }

    /**
     * Rename a class method
     * @param string $class the class that contains the method to be renamed
     * @param string $oldMethod the method to be renamed
     * @param string $newMethod the new method name
     */
    public function setRenameMethod($class, $oldMethod, $newMethod)
    {
        $this->_firstPassListeners[] = new Scisr_Operations_TrackGlobalVariables();
        $this->_firstPassListeners[] = new Scisr_Operations_TrackVariableTypes();
        $this->_firstPassListeners[] = new Scisr_Operations_TrackVariableTypeHints();
        $this->_firstPassListeners[] = new Scisr_Operations_TrackCommentVariableTypes();
        $this->_firstPassListeners[] = new Scisr_Operations_TrackIncludedFiles();
        $this->_listeners[] = new Scisr_Operations_RenameMethod($class, $oldMethod, $newMethod);
    }

    /**
     * Rename a file
     * @param string $oldFilePath the path to the file to be renamed
     * @param string $newFilePath the new path to give it
     */
    public function setRenameFile($oldFilePath, $newFilePath)
    {
        $this->_listeners[] = new Scisr_Operations_ChangeFile($oldFilePath, $newFilePath);
        if (!file_exists($oldFilePath)) {
            $msg = 'does not exist, so will not be moved.';
        } else if (!is_writeable($oldFilePath) || !is_writeable(dirname($newFilePath))) {
            $msg = 'could not be moved.';
        } else {
            Scisr_ChangeRegistry::addRename($oldFilePath, $newFilePath);
        }

        if (isset($msg)) {
            $msg = "File \"$oldFilePath\" " . $msg;
            $this->sendOutput($msg);
        }
    }

    /**
     * Add a file or directory to be parsed
     * @param string $filename the path to the file or directory
     */
    public function addFile($filename)
    {
        $filename = Scisr_File::getAbsolutePath($filename);
        $this->files[] = $filename;
    }

    /**
     * Add multiple files or directories to be parsed
     * @param array an array of file or directory paths
     */
    public function addFiles($fileArray)
    {
        array_map(array($this, 'addFile'), $fileArray);
    }

    /**
     * Set how destructive we are editing
     * @param int one of the following class constants:
     *   + MODE_TIMID: Make no changes to the files, just report possible changes
     *   + MODE_CONSERVATIVE: Make changes we are relatively sure are correct.
     *     Warn about possible changes we aren't sure about.
     *   + MODE_AGGRESSIVE: Make any changes we find.
     */
    public function setEditMode($mode)
    {
        switch ($mode) {
        case self::MODE_TIMID:
            Scisr_ChangeRegistry::set('aggressive', false);
            Scisr_ChangeRegistry::set('timid', true);
            break;
        case self::MODE_CONSERVATIVE:
            Scisr_ChangeRegistry::set('aggressive', false);
            Scisr_ChangeRegistry::set('timid', false);
            break;
        case self::MODE_AGGRESSIVE:
            Scisr_ChangeRegistry::set('aggressive', true);
            Scisr_ChangeRegistry::set('timid', false);
            break;
        }
    }

    /**
     * Perform the requested changes
     */
    public function run()
    {
        Scisr_VariableTypes::init();
        Scisr_FileIncludes::init();

        $sniffer = $this->sniffer;

        // If we need to, make a read-only pass to populate our type information
        if (count($this->_firstPassListeners) > 0) {
            foreach ($this->_firstPassListeners as $listener) {
                $sniffer->addListener($listener);
            }
            $sniffer->process($this->files);
        }

        // NOTE: We do want to keep all the first-pass listeners for this pass.
        // This will ensure that the types are fully populated, but are also
        // populated with the most relevant type assignment when multiples exist.
        foreach ($this->_listeners as $listener) {
            $sniffer->addListener($listener);
        }
        // Run the sniffer
        $sniffer->process($this->files);

        // Get the changes that have been registered
        $changes = Scisr_ChangeRegistry::get('storedChanges');
        if (!is_array($changes)) {
            $changes = array();
        }

        // Display a summary line
        $numFiles = count($changes);
        $msg = "Changed $numFiles files";
        $this->sendOutput($msg);

        // Now make the actual changes
        foreach ($changes as $file) {
            $file->process();
        }

        // If we have any notifications, display them
        $warnings = Scisr_ChangeRegistry::get('storedNotifications');
        if (is_array($warnings) && count($warnings) > 0) {
            // Display a summary
            $numFiles = count($warnings);
            $numWarnings = array_sum(array_map('count', $warnings));
            $msg = "Found $numWarnings possible changes in $numFiles files that were not applied:";
            $this->sendOutput($msg);
            // Now display each line where we found changes
            foreach ($warnings as $filename => $lines) {
                $lines = array_unique($lines);
                foreach ($lines as $lineNo) {
                    $this->sendOutput("$filename:$lineNo");
                }
            }
        }
    }

    /**
     * Send output to the user.
     * @param string $message the message to send
     */
    public function sendOutput($message)
    {
        $this->_output->outputString($message);
    }
}

