<?php
/**
 * Safe formula engine for calculation fields. Never uses eval.
 *
 * The same grammar is implemented in assets/js/calc.js; both must give the same results.
 *
 *   {f12}                  value of field f12 (see Fields::numeric())
 *   + - * / % ^ ( )        arithmetic; division by zero gives 0
 *   < <= > >= == !=        comparisons (1 or 0)
 *   && || !                and, or, not
 *   if(c, a, b)            a when c is not 0, otherwise b
 *   round(x, d) floor(x) ceil(x) abs(x) sqrt(x) min(a, ...) max(a, ...)
 *   days(a, b)             days from date a to date b
 *   lookup(t1, {f2}, {f3}) price table t1, row named by the answer of f2, column by the answer of f3
 *
 * @package SmokyForms
 */

namespace SmokySystems\SmokyForms;

defined( 'ABSPATH' ) || exit;

/**
 * Tokenizer and recursive descent evaluator.
 */
final class Formula {

	/**
	 * Tokens of the formula being evaluated.
	 *
	 * @var array<int,array{0:string,1:string}>
	 */
	private $t = array();

	/**
	 * Position in $t.
	 *
	 * @var int
	 */
	private $p = 0;

	/**
	 * Callback: field id => number.
	 *
	 * @var callable
	 */
	private $num;

	/**
	 * Callback: field id => text answer.
	 *
	 * @var callable
	 */
	private $text;

	/**
	 * Price tables: id => table.
	 *
	 * @var array
	 */
	private $tables;

	/**
	 * Removes characters a formula never contains.
	 *
	 * @param string $formula Raw formula.
	 * @return string
	 */
	public static function clean( $formula ) {
		$formula = preg_replace( '/[^0-9a-z_{}.,()+\-*\/%^<>=!&| ]/i', '', $formula );
		return substr( (string) $formula, 0, 2000 );
	}

	/**
	 * Field ids a formula refers to.
	 *
	 * @param string $formula Formula.
	 * @return string[]
	 */
	public static function refs( $formula ) {
		preg_match_all( '/\{([a-z0-9_]+)\}/', (string) $formula, $m );
		return array_values( array_unique( $m[1] ) );
	}

	/**
	 * Evaluates a formula.
	 *
	 * @param string   $formula Formula.
	 * @param callable $num     Field id => number.
	 * @param callable $text    Field id => text answer (for lookup()).
	 * @param array    $tables  Price tables of the form.
	 * @return float Result; 0 when the formula is not valid.
	 */
	public static function evaluate( $formula, $num, $text = null, $tables = array() ) {
		$e         = new self();
		$e->num    = $num;
		$e->text   = $text ? $text : static function () {
			return '';
		};
		$e->tables = array();
		foreach ( (array) $tables as $table ) {
			if ( isset( $table['id'] ) ) {
				$e->tables[ $table['id'] ] = $table;
			}
		}
		try {
			$e->t = self::tokenize( (string) $formula );
			if ( ! $e->t ) {
				return 0.0;
			}
			$v = $e->expr();
			if ( $e->p !== count( $e->t ) ) {
				throw new \InvalidArgumentException( 'Unexpected token' );
			}
		} catch ( \Exception $ex ) {
			return 0.0;
		}
		return is_finite( $v ) ? $v : 0.0;
	}

	/**
	 * Whether a formula is valid.
	 *
	 * @param string $formula Formula.
	 * @return bool
	 */
	public static function is_valid( $formula ) {
		$e         = new self();
		$e->num    = static function () {
			return 1.0;
		};
		$e->text   = static function () {
			return '';
		};
		$e->tables = array();
		try {
			$e->t = self::tokenize( (string) $formula );
			$e->expr();
			return $e->p === count( $e->t );
		} catch ( \Exception $ex ) {
			return false;
		}
	}

	/**
	 * Splits a formula into tokens.
	 *
	 * @param string $s Formula.
	 * @return array<int,array{0:string,1:string}> [kind, text]; kinds: num, ref, name, op
	 * @throws \InvalidArgumentException On an unknown character.
	 */
	private static function tokenize( $s ) {
		$out = array();
		$n   = strlen( $s );
		$i   = 0;
		while ( $i < $n ) {
			$c = $s[ $i ];
			if ( ' ' === $c ) {
				$i++;
				continue;
			}
			if ( ctype_digit( $c ) || ( '.' === $c && $i + 1 < $n && ctype_digit( $s[ $i + 1 ] ) ) ) {
				preg_match( '/\G[0-9]*\.?[0-9]+/', $s, $m, 0, $i );
				$out[] = array( 'num', $m[0] );
				$i    += strlen( $m[0] );
				continue;
			}
			if ( '{' === $c ) {
				if ( ! preg_match( '/\G\{([a-z0-9_]+)\}/', $s, $m, 0, $i ) ) {
					throw new \InvalidArgumentException( 'Bad field reference' );
				}
				$out[] = array( 'ref', $m[1] );
				$i    += strlen( $m[0] );
				continue;
			}
			if ( ctype_alpha( $c ) ) {
				preg_match( '/\G[a-z_][a-z0-9_]*/i', $s, $m, 0, $i );
				$out[] = array( 'name', strtolower( $m[0] ) );
				$i    += strlen( $m[0] );
				continue;
			}
			$two = substr( $s, $i, 2 );
			if ( in_array( $two, array( '<=', '>=', '==', '!=', '&&', '||' ), true ) ) {
				$out[] = array( 'op', $two );
				$i    += 2;
				continue;
			}
			if ( false !== strpos( '+-*/%^()<>!,', $c ) ) {
				$out[] = array( 'op', $c );
				$i++;
				continue;
			}
			throw new \InvalidArgumentException( 'Unknown character' );
		}
		return $out;
	}

	/**
	 * Whether the next token is the operator $op.
	 *
	 * @param string $op Operator.
	 * @return bool
	 */
	private function is( $op ) {
		return isset( $this->t[ $this->p ] ) && 'op' === $this->t[ $this->p ][0] && $op === $this->t[ $this->p ][1];
	}

	/**
	 * Consumes the operator $op.
	 *
	 * @param string $op Operator.
	 * @throws \InvalidArgumentException When the next token is something else.
	 */
	private function expect( $op ) {
		if ( ! $this->is( $op ) ) {
			throw new \InvalidArgumentException( 'Expected ' . $op );
		}
		$this->p++;
	}

	/**
	 * Expression: or.
	 *
	 * @return float
	 */
	private function expr() {
		$v = $this->and_expr();
		while ( $this->is( '||' ) ) {
			$this->p++;
			$r = $this->and_expr();
			$v = ( 0.0 != $v || 0.0 != $r ) ? 1.0 : 0.0; // phpcs:ignore Universal.Operators.StrictComparisons
		}
		return $v;
	}

	/**
	 * And.
	 *
	 * @return float
	 */
	private function and_expr() {
		$v = $this->cmp();
		while ( $this->is( '&&' ) ) {
			$this->p++;
			$r = $this->cmp();
			$v = ( 0.0 != $v && 0.0 != $r ) ? 1.0 : 0.0; // phpcs:ignore Universal.Operators.StrictComparisons
		}
		return $v;
	}

	/**
	 * Comparison.
	 *
	 * @return float
	 */
	private function cmp() {
		$v = $this->add();
		foreach ( array( '<=', '>=', '==', '!=', '<', '>' ) as $op ) {
			if ( $this->is( $op ) ) {
				$this->p++;
				$r = $this->add();
				switch ( $op ) {
					case '<=':
						return $v <= $r ? 1.0 : 0.0;
					case '>=':
						return $v >= $r ? 1.0 : 0.0;
					case '==':
						return abs( $v - $r ) < 1e-9 ? 1.0 : 0.0;
					case '!=':
						return abs( $v - $r ) < 1e-9 ? 0.0 : 1.0;
					case '<':
						return $v < $r ? 1.0 : 0.0;
					default:
						return $v > $r ? 1.0 : 0.0;
				}
			}
		}
		return $v;
	}

	/**
	 * Sum.
	 *
	 * @return float
	 */
	private function add() {
		$v = $this->mul();
		while ( $this->is( '+' ) || $this->is( '-' ) ) {
			$op = $this->t[ $this->p++ ][1];
			$r  = $this->mul();
			$v  = '+' === $op ? $v + $r : $v - $r;
		}
		return $v;
	}

	/**
	 * Product.
	 *
	 * @return float
	 */
	private function mul() {
		$v = $this->unary();
		while ( $this->is( '*' ) || $this->is( '/' ) || $this->is( '%' ) ) {
			$op = $this->t[ $this->p++ ][1];
			$r  = $this->unary();
			if ( '*' === $op ) {
				$v *= $r;
			} elseif ( 0.0 == $r ) { // phpcs:ignore Universal.Operators.StrictComparisons
				$v = 0.0;
			} else {
				$v = '/' === $op ? $v / $r : fmod( $v, $r );
			}
		}
		return $v;
	}

	/**
	 * Sign and not.
	 *
	 * @return float
	 */
	private function unary() {
		if ( $this->is( '-' ) ) {
			$this->p++;
			return -$this->unary();
		}
		if ( $this->is( '+' ) ) {
			$this->p++;
			return $this->unary();
		}
		if ( $this->is( '!' ) ) {
			$this->p++;
			return 0.0 == $this->unary() ? 1.0 : 0.0; // phpcs:ignore Universal.Operators.StrictComparisons
		}
		return $this->power();
	}

	/**
	 * Power (right associative).
	 *
	 * @return float
	 */
	private function power() {
		$v = $this->primary();
		if ( $this->is( '^' ) ) {
			$this->p++;
			$v = pow( $v, $this->unary() );
		}
		return $v;
	}

	/**
	 * Number, field, function call or parentheses.
	 *
	 * @return float
	 * @throws \InvalidArgumentException On a syntax error.
	 */
	private function primary() {
		if ( ! isset( $this->t[ $this->p ] ) ) {
			throw new \InvalidArgumentException( 'Unexpected end' );
		}
		list( $kind, $text ) = $this->t[ $this->p ];
		if ( 'num' === $kind ) {
			$this->p++;
			return (float) $text;
		}
		if ( 'ref' === $kind ) {
			$this->p++;
			return (float) call_user_func( $this->num, $text );
		}
		if ( 'op' === $kind && '(' === $text ) {
			$this->p++;
			$v = $this->expr();
			$this->expect( ')' );
			return $v;
		}
		if ( 'name' === $kind ) {
			$this->p++;
			return $this->call( $text );
		}
		throw new \InvalidArgumentException( 'Unexpected token' );
	}

	/**
	 * Function call.
	 *
	 * @param string $name Function name.
	 * @return float
	 * @throws \InvalidArgumentException On an unknown function or wrong arguments.
	 */
	private function call( $name ) {
		$this->expect( '(' );

		if ( 'lookup' === $name ) {
			$table = $this->t[ $this->p ] ?? null;
			if ( ! $table || 'name' !== $table[0] ) {
				throw new \InvalidArgumentException( 'Table expected' );
			}
			$this->p++;
			$keys = array();
			while ( $this->is( ',' ) ) {
				$this->p++;
				$ref = $this->t[ $this->p ] ?? null;
				if ( ! $ref || 'ref' !== $ref[0] ) {
					throw new \InvalidArgumentException( 'Field expected' );
				}
				$this->p++;
				$keys[] = (string) call_user_func( $this->text, $ref[1] );
			}
			$this->expect( ')' );
			if ( count( $keys ) < 1 || count( $keys ) > 2 ) {
				throw new \InvalidArgumentException( 'lookup needs one or two fields' );
			}
			return $this->lookup( $table[1], $keys );
		}

		$args = array();
		if ( ! $this->is( ')' ) ) {
			$args[] = $this->expr();
			while ( $this->is( ',' ) ) {
				$this->p++;
				$args[] = $this->expr();
			}
		}
		$this->expect( ')' );
		$n = count( $args );

		switch ( $name ) {
			case 'if':
				if ( 3 !== $n ) {
					break;
				}
				return 0.0 != $args[0] ? $args[1] : $args[2]; // phpcs:ignore Universal.Operators.StrictComparisons
			case 'round':
				if ( $n < 1 || $n > 2 ) {
					break;
				}
				return round( $args[0], $n > 1 ? (int) $args[1] : 0 );
			case 'floor':
				if ( 1 !== $n ) {
					break;
				}
				return floor( $args[0] );
			case 'ceil':
				if ( 1 !== $n ) {
					break;
				}
				return ceil( $args[0] );
			case 'abs':
				if ( 1 !== $n ) {
					break;
				}
				return abs( $args[0] );
			case 'sqrt':
				if ( 1 !== $n ) {
					break;
				}
				return $args[0] < 0 ? 0.0 : sqrt( $args[0] );
			case 'min':
				if ( $n < 1 ) {
					break;
				}
				return (float) min( $args );
			case 'max':
				if ( $n < 1 ) {
					break;
				}
				return (float) max( $args );
			case 'days':
				if ( 2 !== $n ) {
					break;
				}
				return ( 0.0 == $args[0] || 0.0 == $args[1] ) ? 0.0 : $args[1] - $args[0]; // phpcs:ignore Universal.Operators.StrictComparisons
		}
		throw new \InvalidArgumentException( 'Unknown function or wrong arguments: ' . $name );
	}

	/**
	 * Value of a price table cell.
	 *
	 * @param string   $id   Table id.
	 * @param string[] $keys Row answer and, for two-dimensional tables, column answer.
	 * @return float
	 */
	private function lookup( $id, $keys ) {
		if ( empty( $this->tables[ $id ] ) ) {
			return 0.0;
		}
		$table = $this->tables[ $id ];
		$row   = array_search( $keys[0], (array) $table['rows'], true );
		if ( false === $row ) {
			return 0.0;
		}
		$cols = (array) $table['cols'];
		$col  = 0;
		if ( isset( $keys[1] ) && $cols ) {
			$col = array_search( $keys[1], $cols, true );
			if ( false === $col ) {
				return 0.0;
			}
		}
		$cell = $table['cells'][ $row ][ $col ] ?? 0;
		return is_numeric( $cell ) ? (float) $cell : 0.0;
	}
}
