<?php
require_once 'PHPUnit/Framework.php';
require_once '../Scisr.php';

/**
 * @runTestsInSeparateProcesses
 */
class RenameMethodTest extends PHPUnit_Framework_TestCase
{
    public function setUp() {
        $this->test_file = dirname(__FILE__) . '/myTestFile.php';
        touch($this->test_file);
    }

    public function tearDown() {
        unlink($this->test_file);
    }

    public function populateFile($contents) {
        $handle = fopen($this->test_file, 'w');
        fwrite($handle, $contents);
    }

    public function compareFile($expected) {
        $contents = file_get_contents($this->test_file);
        $this->assertEquals($expected, $contents);
    }

    public function renameAndCompare($original, $expected, $class='Foo', $oldmethod='bar', $newmethod='baz') {
        $this->populateFile($original);

        $s = new Scisr();
        $s->setRenameMethod($class, $oldmethod, $newmethod);
        $s->addFile($this->test_file);
        $s->run();

        $this->compareFile($expected);

    }

    public function testRenameMethodDeclaration() {
        $orig = <<<EOL
<?php
class Foo {
    function bar() {
    }
}
EOL;
        $expected = <<<EOL
<?php
class Foo {
    function baz() {
    }
}
EOL;
        $this->renameAndCompare($orig, $expected);
    }

    public function testRenameMethodInstantiatedCall() {
        $orig = <<<EOL
<?php
\$f = new Foo();
\$result = \$f->bar();
\$f2 = \$f;
\$result = \$f2->bar();
EOL;
        $expected = <<<EOL
<?php
\$f = new Foo();
\$result = \$f->baz();
\$f2 = \$f;
\$result = \$f2->baz();
EOL;
        $this->renameAndCompare($orig, $expected);
    }

    public function testRenameMethodInsideOwningClass() {
        $orig = <<<EOL
<?php
class Foo {
    function quark() {
        \$this->bar();
        \$foo = \$this;
        \$foo->bar();
    }
}
EOL;
        $expected = <<<EOL
<?php
class Foo {
    function quark() {
        \$this->baz();
        \$foo = \$this;
        \$foo->baz();
    }
}
EOL;
        $this->renameAndCompare($orig, $expected);
    }

    public function testRecognizeOverwrittenVariables() {
        $orig = <<<EOL
<?php
\$a = new NotFoo();
\$b = new Foo();

\$a = new Foo();
\$b = new NotFoo();

\$a->bar();
\$b->bar();
EOL;
        $expected = <<<EOL
<?php
\$a = new NotFoo();
\$b = new Foo();

\$a = new Foo();
\$b = new NotFoo();

\$a->baz();
\$b->bar();
EOL;
        $this->renameAndCompare($orig, $expected);
    }

    public function testRenameWithScopedVariable() {
        $orig = <<<EOL
<?php
function quark(\$param) {
    \$f = new Foo();
    return \$f->bar();
}
EOL;
        $expected = <<<EOL
<?php
function quark(\$param) {
    \$f = new Foo();
    return \$f->baz();
}
EOL;
        $this->renameAndCompare($orig, $expected);
    }

    public function testOnlyRenameIfInScope() {
        $orig = <<<EOL
<?php
\$f = new NotFoo();
function quark(\$param) {
    \$f = new Foo();
    return \$f->bar();
}
\$result = \$f->bar();
EOL;
        $expected = <<<EOL
<?php
\$f = new NotFoo();
function quark(\$param) {
    \$f = new Foo();
    return \$f->baz();
}
\$result = \$f->bar();
EOL;
        $this->renameAndCompare($orig, $expected);
    }

    public function testRenameGlobalVariable() {
        $orig = <<<EOL
<?php
\$f = new Foo();
function quark(\$param) {
    global \$f;
    return \$f->bar();
}
EOL;
        $expected = <<<EOL
<?php
\$f = new Foo();
function quark(\$param) {
    global \$f;
    return \$f->baz();
}
EOL;
        $this->renameAndCompare($orig, $expected);
    }

    public function testDontRenameNonGlobalVariable() {
        $orig = <<<EOL
<?php
\$f = new Foo();
function quark(\$param) {
    return \$f->bar();
}
EOL;
        $expected = <<<EOL
<?php
\$f = new Foo();
function quark(\$param) {
    return \$f->bar();
}
EOL;
        $this->renameAndCompare($orig, $expected);
    }

    public function testRenameWhenInsideIf() {
        $orig = <<<EOL
<?php
if (true) {
    \$f = new Foo(true);
} else {
    \$f = new Foo(false);
}
\$result = \$f->bar();
EOL;
        $expected = <<<EOL
<?php
if (true) {
    \$f = new Foo(true);
} else {
    \$f = new Foo(false);
}
\$result = \$f->baz();
EOL;
        $this->renameAndCompare($orig, $expected);
    }

    public function testRenameFunctionParameterWithPHPDocType() {
        $this->markTestIncomplete();
    }

    public function testRenameClassPropertyCallWithPHPDocType() {
        $this->markTestIncomplete();
    }

    public function testRenameClassPropertyCallWithConstructorInstantiation() {
        $this->markTestIncomplete();
    }

    public function testRenameUnassignedVariableWithPHPDocTypeHint() {
        $this->markTestIncomplete();
    }

    public function testRenameMethodStaticCall() {
        $orig = <<<EOL
<?php
\$result = Foo::bar();
EOL;
        $expected = <<<EOL
<?php
\$result = Foo::baz();
EOL;
        $this->renameAndCompare($orig, $expected);
    }

}
