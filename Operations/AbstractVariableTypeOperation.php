<?php

// The scope in which we store qualified class types
define('SCISR_SCOPE_CLASS', 0);
// The scope in which we store global variable types
define('SCISR_SCOPE_GLOBAL', 0);

/**
 * An abstract operation class that helps you deal with variable types
 *
 * This should sit completely between any Scisr Operations and the
 * Scisr_Db_VariableTypes storage class, since that may change formats,
 * and this has domain knowledge about CodeSniffer.
 */
abstract class Scisr_Operations_AbstractVariableTypeOperation extends Scisr_Operations_AbstractChangeOperation implements PHP_CodeSniffer_Sniff
{

    /**
     * Get the type of a variable
     * @param PHP_CodeSniffer_File $phpcsFile The file the variable is in
     * @param int $varPtr The position in the stack in which our variable has scope
     * @param string $varName the name of the variable. If not provided, will
     * be determined from $varPtr.
     * @return string|null the class name, or null if we don't know
     */
    protected function getVariableType($varPtr, $phpcsFile, $varName=null)
    {
        $tokens = $phpcsFile->getTokens();
        $varInfo = $tokens[$varPtr];

        if ($varName === null) {
            $varName = $varInfo['content'];
        }

        // Special case: $this inside a class
        if ($varName == '$this'
            && ($classDefPtr = array_search(T_CLASS, $varInfo['conditions'])) !== false
        ) {
            $classPtr = $phpcsFile->findNext(T_STRING, $classDefPtr);
            $type = $tokens[$classPtr]['content'];
            return $type;
        }

        $scopeOpen = $this->getScopeOwner($varPtr, $phpcsFile, $varName);

        // If we find the type in this file, return it
        $result = Scisr_Db_VariableTypes::getVariableType($varName, $phpcsFile->getFileName(), $scopeOpen, $varPtr);
        if ($result === null) {
            // If not, we'll look in any included files
            $includedFiles = $this->_dbFileIncludes->getIncludedFiles($phpcsFile->getFileName());
            //TODO we could do one query with filenames joined - we would need to 
            // ensure correct ordering, though
            foreach ($includedFiles as $file) {
                $result = Scisr_Db_VariableTypes::getVariableType($varName, $file, $scopeOpen, $varPtr);
                if ($result !== null) {
                    break;
                }
            }
        }

        // If our result is not completely specific, try to resolve it further
        if ($result !== null && self::getVariableSpecificity($result) > 0) {
            $newResult = $this->getVariableType($varPtr, $phpcsFile, $result);
            if ($newResult !== null) {
                $result = $newResult;
            }
        }
        return $result;
    }

    /**
     * Set the type of a variable
     * @param PHP_CodeSniffer_File $phpcsFile The file the variable is in
     * @param int $varPtr a pointer to the beginning of the variable
     * @param string $type the name of the class that this variable holds
     * @param string $varName the name of the variable. If not provided, will
     * be determined from $varPtr.
     * @param int $scopeOpen a pointer to the element owning the variable's scope.
     * If not provided, will be determined from $varPtr.
     */
    protected function setVariableType($varPtr, $type, $phpcsFile, $varName=null, $scopeOpen=null)
    {
        $tokens = $phpcsFile->getTokens();
        $varInfo = $tokens[$varPtr];

        if ($varName === null) {
            $varName = $varInfo['content'];
        }

        if ($scopeOpen === null) {
            $scopeOpen = $this->getScopeOwner($varPtr, $phpcsFile, $varName);
        }

        // Special case: property or method declaration inside a class
        // Change the variable name to match the way it will be referenced
        if ($scopeOpen !== null && $tokens[$scopeOpen]['code'] == T_CLASS) {
            $classNamePtr = $phpcsFile->findNext(T_STRING, $scopeOpen);
            $className = $tokens[$classNamePtr]['content'];
            // If it's a property, strip off the $ symbol
            if (substr($varName, 0, 1) == '$') {
                $varName = substr($varName, 1);
            }
            $varName = $className . '->' . $varName;
            // Recalculate the owning scope in case it has changed
            $scopeOpen = $this->getScopeOwner($varPtr, $phpcsFile, $varName);
        }

        // If a type has already been set for this variable that is more 
        // specific than this type, we don't overwrite it
        $existing = Scisr_Db_VariableTypes::checkVariableDefinition($phpcsFile->getFileName(), $varPtr);
        if ($existing !== null) {
            $existingSpecificity = self::getVariableSpecificity($existing);
            $typeSpecificity = self::getVariableSpecificity($type);
            if ($typeSpecificity >= $existingSpecificity) {
                return;
            }
        }

        if ($varName == $type) {
            return;
        }

        Scisr_Db_VariableTypes::registerVariableType($varName, $type, $phpcsFile->getFileName(), $scopeOpen, $varPtr);
    }

    /**
     * Measure how specifically typed a variable name is.
     *
     * A single class name has a specificity of 0. Each variable and function
     * adds 1 to the specificity.
     *
     * @param string the full variable name
     * @return int the specificity of that variable name
     */
    public static function getVariableSpecificity($varName)
    {
        $pieces = explode('->', $varName);
        $specificity = count($pieces) - 1;
        $char = $pieces[0]{0};
        if ($char == '$' || $char == '*') {
            $specificity++;
        }
        return $specificity;
    }

    /**
     * Filter the array of scopes we get from CodeSniffer
     *
     * We don't want things like conditionals in our scope list, since for our
     * purposes we're just ignoring those.
     *
     * @param array a list of stack pointers => token types as Codesniffer generates them
     * @return an array of stack pointers we care about
     */
    protected static function filterScopes($scopes)
    {
        $acceptScope = create_function('$type', 'return (in_array($type, array(T_CLASS, T_INTERFACE, T_FUNCTION)));');
        $scopes = array_keys(array_filter($scopes, $acceptScope));
        return $scopes;
    }

    /**
     * Set a variable as global
     * @param int $varPtr The position in the stack in which our variable has scope
     * @param PHP_CodeSniffer_File $phpcsFile The file the variable is in
     */
    protected function setGlobal($varPtr, $phpcsFile)
    {
        $tokens = $phpcsFile->getTokens();
        $varName = $tokens[$varPtr]['content'];
        $scopeOpen = $this->getScopeOwner($varPtr, $phpcsFile, $varName);
        Scisr_Db_VariableTypes::registerGlobalVariable($varName, $phpcsFile->getFileName(), $scopeOpen, $varPtr);
    }

    /**
     * Figure out the relevant scope opener
     * @param PHP_CodeSniffer_File $phpcsFile The file the variable is in
     * @param int $varPtr The position in the stack in which our variable has scope
     * @param string $varName the name of the variable.
     * @return int the stack pointer that opens the scope for this variable
     */
    private function getScopeOwner($varPtr, $phpcsFile, $varName)
    {
        $tokens = $phpcsFile->getTokens();
        $varInfo = $tokens[$varPtr];

        $scopes = self::filterScopes($varInfo['conditions']);

        if ($varName{0} != '$' && $varName{0} != '*') {
            // If we're dealing with a fully qualified variable, put it in the global scope
            $scopeOpen = SCISR_SCOPE_CLASS;
        } else if ($this->isGlobal($varPtr, $phpcsFile->getFileName(), $scopes, $varName)) {
            // If the variable was declared global, use that
            $scopeOpen = SCISR_SCOPE_GLOBAL;
        } else {
            // Get the lowermost scope
            $scopeOpen = $scopes[count($scopes) - 1];
        }
        return $scopeOpen;
    }

    /**
     * See if a variable is in the global scope
     * @param int $varPtr The position in the stack in which our variable has scope
     * @param string $name the name of the variable (including the dollar sign)
     * @param string $filename the file we're in
     * @param array $scopes an array of scope opener pointers (not as received from CodeSniffer)
     * @return boolean true if the variable is global
     */
    private function isGlobal($varPtr, $filename, $scopes, $name)
    {

        // If we have no scope, we're global without trying
        if (count($scopes) == 0) {
            return true;
        }
        // Get the lowermost scope
        $scopeOpen = $scopes[count($scopes) - 1];

        return Scisr_Db_VariableTypes::isGlobalVariable($name, $filename, $scopeOpen, $varPtr);
    }

    /**
     * Resolve the subject of a static method call to the most typed object we can
     * @param PHP_CodeSniffer_File $phpcsFile
     * @param int $stackPtr a pointer to the T_PAAMAYIM_NEKUDOTAYIM token
     * (the "::" symbol)
     * @return string the class this method was called on
     */
    protected function resolveStaticSubject($phpcsFile, $stackPtr)
    {
        $tokens = $phpcsFile->getTokens();
        $classPtr = $phpcsFile->findPrevious(array(T_STRING, T_SELF, T_PARENT), $stackPtr);
        $classInfo = $tokens[$classPtr];
        $className = $classInfo['content'];
        if (($className == 'self' || $className == 'parent')
            && ($classDefPtr = array_search(T_CLASS, $classInfo['conditions'])) !== false
        ) {
            $newClassPtr = $phpcsFile->findNext(T_STRING, $classDefPtr);
            $newClassName = $tokens[$newClassPtr]['content'];
            if ($className == 'self') {
                return $newClassName;
            } else {
                $parent = $this->_dbClasses->getParent($newClassName);
                if ($parent !== null) {
                    return $parent;
                }
            }
        }
        return $className;
    }

    /**
     * Resolve a set of variable tokens to the most typed object we can
     * @param int $ptr a pointer to the first or last token of the variable.
     * Must not be whitespace.
     * @param PHP_CodeSniffer_File $phpcsFile
     * @param boolean $lookForward If true, we are starting at the beginning of 
     * the variable and moving forwards. If false, we are starting at the end 
     * and moving backwards. Defaults to true.
     * @return string a type name or a partially-resolved string, such as
     * "Foo->unknownVar->property".
     */
    protected function resolveFullVariableType($ptr, $phpcsFile, $lookForward=true)
    {
        $tokens = $phpcsFile->getTokens();

        if ($lookForward) {
            // Special treatment for class instantiations
            if ($tokens[$ptr]['code'] == T_NEW) {
                $classPtr = $phpcsFile->findNext(T_STRING, $ptr);
                $classToken = $tokens[$classPtr];
                $className = $classToken['content'];
                return $className;
            }
            $startPtr = $ptr;
            $endPtr = $this->getEndOfVar($ptr, $tokens);
        } else {
            $endPtr = $ptr;
            $startPtr = $this->getStartOfVar($ptr, $tokens);
        }

        $soFar = '';
        $currPtr = $startPtr;
        do {
            list($currPtr, $nextChunk) = $this->getNextChunk($currPtr, $endPtr, $tokens);
            $soFar .= $nextChunk;
            // See if the string resolves to a type now
            $type = $this->getVariableType($startPtr, $phpcsFile, $soFar);
            if ($type !== null) {
                $soFar = $type;
            }
        } while ($currPtr <= $endPtr);
        return $soFar;
    }

    private function getNextChunk($currPtr, $endPtr, $tokens)
    {

        $soFar = '';
        while ($currPtr <= $endPtr) {

            $currToken = $tokens[$currPtr];

            // We treat -> as its own chunk because otherwise we are resolving
            // types with a -> prefixed, which is bad form in general and causes 
            // problems with method prefixing specifically. Unfortunately, this 
            // means that the parent function will try to resolve types ending 
            // in a ->, which should be harmless but isn't ideal.
            if ($currToken['code'] == T_PAAMAYIM_NEKUDOTAYIM || $currToken['code'] == T_OBJECT_OPERATOR) {
                // If we are at the beginning, return just the separator
                if ($soFar == '') {
                    // We normalize static invocations for simplicity
                    $soFar .= '->';
                    $currPtr = $this->stepForward($currPtr, $tokens, array(T_WHITESPACE));
                }
                break;
            }

            if ($currToken['code'] == T_OPEN_PARENTHESIS) {
                // Mark this as a function
                $soFar = '*' . $soFar;
            } else {
                // Add the token to our string
                $soFar .= $currToken['content'];
            }
            $currPtr = $this->stepForward($currPtr, $tokens, array(T_WHITESPACE));

        }
        return array($currPtr, $soFar);
    }

    /**
     * Get the start position of a variable declaration
     * @param int $varPtr a pointer to the end of the variable tokens
     * @param array $tokens the token stack
     * @return int a pointer to the first token that makes up this variable
     */
    protected function getStartOfVar($varPtr, $tokens)
    {
        $startPtr = $this->traverseVar('stepBackward', T_CLOSE_PARENTHESIS, $varPtr, $tokens);
        // If our ending token is a close paren, hop over to the opener
        if ($tokens[$startPtr]['code'] == T_CLOSE_PARENTHESIS) {
            $startPtr = $tokens[$startPtr]['parenthesis_opener'];
        }
        return $startPtr;
    }

    /**
     * Get the end position of a variable declaration
     * @param int $varPtr a pointer to the start of the variable tokens
     * @param array $tokens the token stack
     * @return int a pointer to the last token that makes up this variable
     */
    protected function getEndOfVar($varPtr, $tokens)
    {
        $endPtr = $this->traverseVar('stepForward', T_OPEN_PARENTHESIS, $varPtr, $tokens);
        // If our ending token is a open paren, hop over to the closer
        if ($tokens[$endPtr]['code'] == T_OPEN_PARENTHESIS) {
            $endPtr = $tokens[$endPtr]['parenthesis_closer'];
        }
        return $endPtr;
    }

    /**
     * @param string $traverseMethod the name of this class method to move over 
     * the token stack
     * @param int $acceptParen a token constant representing which type of 
     * parentheses to accept
     * @return int a pointer the the place in the stack that we halted
     */
    private function traverseVar($traverseMethod, $acceptParen, $varPtr, $tokens)
    {
        // Tokens we expect to see in a variable
        $accept = array(T_VARIABLE, T_STRING, T_OBJECT_OPERATOR, T_PAAMAYIM_NEKUDOTAYIM, T_DOLLAR, $acceptParen);
        // Technically initialization isn't necessary, but it prevents an error
        // if we happen to call this on something that isn't a recognized var
        $prevPtr = $varPtr;
        // Look until we find a token that's not accepted
        while (in_array($tokens[$varPtr]['code'], $accept)) {
            $prevPtr = $varPtr;
            $varPtr = $this->$traverseMethod($varPtr, $tokens, array(T_WHITESPACE));
        }
        return $prevPtr;
    }

    /**
     * Step forward in the token stack.
     * @param int $currPtr the beginning position in the stack
     * @param array $tokens the token stack
     * @param array $ignore an array of token codes to be ignored
     * @return int a pointer to the next token, ignoring any given types and 
     * skipping over parenthesized statements (this will halt on the open 
     * parenthesis, but not on the matching close parenthesis)
     */
    private function stepForward($currPtr, $tokens, $ignore)
    {
        do {
            if ($tokens[$currPtr]['code'] == T_OPEN_PARENTHESIS) {
                $currPtr = $tokens[$currPtr]['parenthesis_closer'];
            }
            $currPtr++;
        } while (in_array($tokens[$currPtr]['code'], $ignore));

        return $currPtr;
    }

    /**
     * Step backward in the token stack.
     * @param int $currPtr the beginning position in the stack
     * @param array $tokens the token stack
     * @param array $ignore an array of token codes to be ignored
     * @return int a pointer to the previous token, ignoring any given types and 
     * skipping over parenthesized statements (this will halt on the close 
     * parenthesis, but not on the matching open parenthesis)
     */
    private function stepBackward($currPtr, $tokens, $ignore)
    {
        do {
            if ($tokens[$currPtr]['code'] == T_CLOSE_PARENTHESIS) {
                $currPtr = $tokens[$currPtr]['parenthesis_opener'];
            }
            $currPtr--;
        } while (in_array($tokens[$currPtr]['code'], $ignore));

        return $currPtr;
    }

}
