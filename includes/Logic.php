<?php
/**
 * Conditional logic: show or hide a field depending on the answers.
 *
 * @package SmokyForms
 */

namespace SmokySystems\SmokyForms;

defined( 'ABSPATH' ) || exit;

/**
 * The same rules run in the browser (form.js). On the server they decide which fields
 * are left out: a hidden field is not validated, counts as empty in calculations
 * and is not in the e-mails.
 */
final class Logic {

	/**
	 * Comparison operators.
	 */
	const OPS = array( 'is', 'is_not', 'empty', 'not_empty', 'gt', 'lt', 'contains' );

	/**
	 * Cleans the logic settings of a field.
	 *
	 * @param mixed $l Raw logic.
	 * @return array|null Null when the field has no logic.
	 */
	public static function sanitize( $l ) {
		if ( ! is_array( $l ) || empty( $l['enabled'] ) ) {
			return null;
		}
		$rules = array();
		foreach ( (array) ( $l['rules'] ?? array() ) as $r ) {
			if ( ! is_array( $r ) ) {
				continue;
			}
			$field = isset( $r['field'] ) ? preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) $r['field'] ) ) : '';
			$op    = isset( $r['op'] ) && in_array( $r['op'], self::OPS, true ) ? $r['op'] : 'is';
			if ( '' === $field ) {
				continue;
			}
			$rules[] = array(
				'field' => $field,
				'op'    => $op,
				'value' => isset( $r['value'] ) ? sanitize_text_field( (string) $r['value'] ) : '',
			);
		}
		if ( ! $rules ) {
			return null;
		}
		return array(
			'enabled' => true,
			'action'  => isset( $l['action'] ) && 'hide' === $l['action'] ? 'hide' : 'show',
			'match'   => isset( $l['match'] ) && 'any' === $l['match'] ? 'any' : 'all',
			'rules'   => $rules,
		);
	}

	/**
	 * Ids of the fields hidden by their logic for these answers.
	 *
	 * A rule about a hidden field sees an empty answer, so hiding can chain;
	 * the rules are applied until nothing changes.
	 *
	 * @param array $form   Form.
	 * @param array $values Values by field id (with calculation results).
	 * @return array<string,true>
	 */
	public static function hidden( $form, $values ) {
		$by_id = array();
		foreach ( $form['fields'] as $f ) {
			$by_id[ $f['id'] ] = $f;
		}
		$hidden = array();
		$passes = count( $form['fields'] ) + 1;
		for ( $pass = 0; $pass < $passes; $pass++ ) {
			$changed = false;
			foreach ( $form['fields'] as $f ) {
				if ( empty( $f['logic'] ) ) {
					continue;
				}
				$hide = ! self::shown( $f['logic'], $by_id, $values, $hidden );
				if ( $hide !== isset( $hidden[ $f['id'] ] ) ) {
					$changed = true;
					if ( $hide ) {
						$hidden[ $f['id'] ] = true;
					} else {
						unset( $hidden[ $f['id'] ] );
					}
				}
			}
			if ( ! $changed ) {
				break;
			}
		}
		return $hidden;
	}

	/**
	 * Whether a field with this logic is shown.
	 *
	 * @param array $logic  Logic.
	 * @param array $by_id  Fields by id.
	 * @param array $values Values.
	 * @param array $hidden Hidden ids so far.
	 * @return bool
	 */
	private static function shown( $logic, $by_id, $values, $hidden ) {
		$any = 'any' === $logic['match'];
		$hit = ! $any;
		foreach ( $logic['rules'] as $r ) {
			$f     = $by_id[ $r['field'] ] ?? null;
			$value = ( $f && ! isset( $hidden[ $r['field'] ] ) ) ? ( $values[ $r['field'] ] ?? '' ) : '';
			$ok    = self::test( $f, $value, $r['op'], $r['value'] );
			if ( $any && $ok ) {
				$hit = true;
				break;
			}
			if ( ! $any && ! $ok ) {
				$hit = false;
				break;
			}
		}
		return 'show' === $logic['action'] ? $hit : ! $hit;
	}

	/**
	 * One rule.
	 *
	 * @param array|null $field Field the rule is about.
	 * @param mixed      $value Its answer.
	 * @param string     $op    Operator.
	 * @param string     $want  Value of the rule.
	 * @return bool
	 */
	public static function test( $field, $value, $op, $want ) {
		$list  = is_array( $value ) ? array_map( 'strval', $value ) : ( '' === (string) $value ? array() : array( (string) $value ) );
		$empty = ! $list;
		switch ( $op ) {
			case 'empty':
				return $empty;
			case 'not_empty':
				return ! $empty;
			case 'gt':
			case 'lt':
				if ( $empty || ! $field ) {
					return false;
				}
				$want = str_replace( ',', '.', trim( $want ) );
				if ( ! is_numeric( $want ) ) {
					return false;
				}
				$n = in_array( $field['type'], array( 'number', 'calculation', 'rating', 'text', 'hidden' ), true ) ? (float) str_replace( ',', '.', $list[0] ) : Fields::numeric( $field, $value );
				return 'gt' === $op ? $n > (float) $want : $n < (float) $want;
			case 'contains':
				return '' !== $want && false !== mb_stripos( implode( ' ', $list ), $want );
			case 'is_not':
				return ! self::equals( $list, $want );
			default:
				return self::equals( $list, $want );
		}
	}

	/**
	 * Whether one of the answers equals the rule value (case-insensitive; numbers by value).
	 *
	 * @param string[] $list Answers.
	 * @param string   $want Rule value.
	 * @return bool
	 */
	private static function equals( $list, $want ) {
		$want = trim( $want );
		if ( ! $list ) {
			return '' === $want;
		}
		foreach ( $list as $v ) {
			$v = trim( $v );
			$a = str_replace( ',', '.', $v );
			$b = str_replace( ',', '.', $want );
			if ( ( is_numeric( $a ) && is_numeric( $b ) && (float) $a === (float) $b ) || 0 === strcasecmp( $v, $want ) || mb_strtolower( $v ) === mb_strtolower( $want ) ) {
				return true;
			}
		}
		return false;
	}
}
