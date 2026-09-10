<?php
namespace Areanet\PIM\Classes\ORM\Spatial;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\TokenType;

/**
 * POINT_STR function for querying using Point objects as parameters
 *
 * Usage: POINT_STR(:param) where param should be mapped to $point where $point is Wantlet\ORM\Point
 *        without any special typing provided (eg. so that it gets converted to string)
 */
class PointStr extends FunctionNode {
    private $arg;

    public function getSql(\Doctrine\ORM\Query\SqlWalker $sqlWalker): string {
        return 'GeomFromText(' . $this->arg->dispatch($sqlWalker) . ')';
    }

    public function parse(\Doctrine\ORM\Query\Parser $parser): void {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $this->arg = $parser->ArithmeticPrimary();
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }

}