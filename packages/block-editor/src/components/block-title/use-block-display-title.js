/**
 * External dependencies
 */
import { truncate } from 'lodash';

/**
 * WordPress dependencies
 */
import { useSelect } from '@wordpress/data';
import {
	getBlockType,
	__experimentalGetBlockLabel as getBlockLabel,
	isReusableBlock,
} from '@wordpress/blocks';

/**
 * Internal dependencies
 */
import useBlockDisplayInformation from '../use-block-display-information';
import { store as blockEditorStore } from '../../store';

/**
 * Returns the block's configured title as a string, or empty if the title
 * cannot be determined.
 *
 * @example
 *
 * ```js
 * useBlockDisplayTitle( 'afd1cb17-2c08-4e7a-91be-007ba7ddc3a1', 17 );
 * ```
 *
 * @param {string} clientId Client ID of block.
 * @param {number|undefined} maximumLength The maximum length that the block title string may be before truncated.
 * @return {?string} Block title.
 */
export default function useBlockDisplayTitle( clientId, maximumLength ) {
	const { attributes, name, reusableBlockTitle } = useSelect(
		( select ) => {
			if ( ! clientId ) {
				return {};
			}
			const {
				getBlockName,
				getBlockAttributes,
				__experimentalGetReusableBlockTitle,
			} = select( blockEditorStore );
			const blockName = getBlockName( clientId );
			if ( ! blockName ) {
				return {};
			}
			const isReusable = isReusableBlock( getBlockType( blockName ) );
			return {
				attributes: getBlockAttributes( clientId ),
				name: blockName,
				reusableBlockTitle:
					isReusable &&
					__experimentalGetReusableBlockTitle(
						getBlockAttributes( clientId ).ref
					),
			};
		},
		[ clientId ]
	);

	const blockInformation = useBlockDisplayInformation( clientId );
	if ( ! name || ! blockInformation ) {
		return null;
	}
	const blockType = getBlockType( name );
	const blockLabel = blockType
		? getBlockLabel( blockType, attributes )
		: null;
	const label = reusableBlockTitle || blockLabel;
	// Label will fallback to the title if no label is defined for the current
	// label context. If the label is defined we prioritize it over possible
	// possible block variation title match.
	if ( label && label !== blockType.title ) {
		return maximumLength && maximumLength > 0
			? truncate( label, { length: maximumLength } )
			: label;
	}
	return blockInformation.title;
}
