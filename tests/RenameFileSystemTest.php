<?php
require_once 'MultipleFileTest.php';

/**
 * @runTestsInSeparateProcesses
 */
class RenameFileSystemTest extends Scisr_Tests_MultipleFileTestCase
{

    public function testRenameFileAndCompareDir() {
        $this->populateDir(dirname(__FILE__) . '/_files/renameFileFixture', $this->test_dir);

        $this->doRenameFile($this->test_dir . '/stuff.php', $this->test_dir . '/things.php');

        $this->compareDir(dirname(__FILE__) . '/_files/renameFileFixture-after-rename-file', $this->test_dir);
    }

    public function testRenameFileToOtherDirAndCompare() {
        $this->populateDir(dirname(__FILE__) . '/_files/renameFileFixture', $this->test_dir);

        $this->doRenameFile($this->test_dir . '/stuff.php', $this->test_dir . '/otherfolder/things.php');

        $this->compareDir(dirname(__FILE__) . '/_files/renameFileFixture-after-rename-file-other-dir', $this->test_dir);
    }

    public function testRenameFileToNewDirAndCompare() {
        $this->populateDir(dirname(__FILE__) . '/_files/renameFileFixture', $this->test_dir);

        $this->doRenameFile($this->test_dir . '/stuff.php', $this->test_dir . '/newfolder/subdir/things.php');

        $this->compareDir(dirname(__FILE__) . '/_files/renameFileFixture-after-rename-file-new-dir', $this->test_dir);
    }

    public function testRenameFileWithRelativePath() {
        $this->populateDir(dirname(__FILE__) . '/_files/renameFileFixture', $this->test_dir);
        chdir($this->test_dir);

        $this->doRenameFile('stuff.php', 'things.php');

        $this->compareDir(dirname(__FILE__) . '/_files/renameFileFixture-after-rename-file', $this->test_dir);
    }

    public function testDontMoveFileInTimidMode() {
        $this->populateDir(dirname(__FILE__) . '/_files/renameFileFixture', $this->test_dir);

        $s = new Scisr();
        $s->setEditMode(Scisr::MODE_TIMID);
        $s->setRenameFile($this->test_dir . '/test2.php', $this->test_dir . '/otherfolder/things.php');
        $s->addFile($this->test_dir);
        $s->run();

        $this->compareDir(dirname(__FILE__) . '/_files/renameFileFixture', $this->test_dir);
    }

	/**
	 * @dataProvider includesRenameProvider
	 */
    public function testRenameFileAltersIncludes($oldName, $newName, $expectedDir) {
		$this->markTestIncomplete('Feature is not yet implemented');
        $this->populateDir(dirname(__FILE__) . '/_files/renameFileIncludesFixture', $this->test_dir);
        chdir($this->test_dir);

		$this->doRenameFile($oldName, $newName);

        $this->compareDir(dirname(__FILE__) . '/_files/' . $expectedDir, $this->test_dir);
    }

	public function includesRenameProvider() {
		return array(
			array('test.php', 'otherfolder/test.php', 'renameFileIncludesFixture-after-rename-1'),
			array('otherfolder/stuff.php', 'stuff.php', 'renameFileIncludesFixture-after-rename-2'),
			array('test2.php', 'test3.php', 'renameFileIncludesFixture-after-rename-4'),
			array('test2.php', 'otherfolder/test2.php', 'renameFileIncludesFixture-after-rename-5'),
			array('test2.php', '../test2.php', 'renameFileIncludesFixture-after-rename-6'),
		);
	}

    public function testRenameFileAltersIncludesSwitchPlaces() {
		$this->markTestIncomplete('Feature is not yet implemented');
        $this->populateDir(dirname(__FILE__) . '/_files/renameFileIncludesFixture', $this->test_dir);
        chdir($this->test_dir);

		$this->doRenameFile('otherfolder/stuff.php', 'stuff.php');
		$this->doRenameFile('test.php', 'otherfolder/test.php');

        $this->compareDir(dirname(__FILE__) . '/_files/renameFileIncludesFixture-after-rename-3', $this->test_dir);
    }

	public function doRenameFile($old, $new) {
        $s = new Scisr();
        $s->setRenameFile($old, $new);
        $s->addFile($this->test_dir);
        $s->run();
	}

}
