/**
 * External dependencies
 */
import { compact } from 'lodash';

/**
 * WordPress dependencies
 */
import { Fragment } from '@wordpress/element';

/**
 * Internal dependencies
 */
import ListViewBlock from './block';
import ListViewAppender from './appender';
import { isClientIdSelected } from './utils';
import { useListViewContext } from './context';

function countBlocks( block, expandedState, draggedClientIds ) {
	const isDragged = draggedClientIds?.includes( block.clientId );
	if ( isDragged ) {
		return 0;
	}
	const isExpanded = expandedState[ block.clientId ] ?? true;
	if ( isExpanded ) {
		return (
			1 +
			block.innerBlocks.reduce(
				countReducer( expandedState, draggedClientIds ),
				0
			)
		);
	}
	return 1;
}
const countReducer = ( expandedState, draggedClientIds ) => (
	count,
	block
) => {
	const isDragged = draggedClientIds?.includes( block.clientId );
	if ( isDragged ) {
		return count;
	}
	const isExpanded = expandedState[ block.clientId ] ?? true;
	if ( isExpanded && block.innerBlocks.length > 0 ) {
		return count + countBlocks( block, expandedState, draggedClientIds );
	}
	return count + 1;
};

export default function ListViewBranch( props ) {
	const {
		blocks,
		selectBlock,
		showAppender,
		showBlockMovers,
		showNestedBlocks,
		parentBlockClientId,
		level = 1,
		path = '',
		isBranchSelected = false,
		isLastOfBranch = false,
		listPosition = 0,
		fixedListWindow,
	} = props;

	const {
		expandedState,
		draggedClientIds,
		selectedClientIds,
		__experimentalPersistentListViewFeatures,
	} = useListViewContext();

	const isTreeRoot = ! parentBlockClientId;
	const filteredBlocks = compact( blocks );
	const itemHasAppender = ( parentClientId ) =>
		showAppender &&
		! isTreeRoot &&
		isClientIdSelected( parentClientId, selectedClientIds );
	const hasAppender = itemHasAppender( parentBlockClientId );
	// Add +1 to the rowCount to take the block appender into account.
	const blockCount = filteredBlocks.length;
	const rowCount = hasAppender ? blockCount + 1 : blockCount;
	const appenderPosition = rowCount;
	let nextPosition = listPosition;

	const listItems = [];
	for ( let index = 0; index < filteredBlocks.length; index++ ) {
		const block = filteredBlocks[ index ];
		const { clientId, innerBlocks } = block;

		if ( index > 0 ) {
			nextPosition += countBlocks(
				filteredBlocks[ index - 1 ],
				expandedState,
				draggedClientIds
			);
		}

		const usesWindowing = __experimentalPersistentListViewFeatures;
		const {
			start,
			end,
			itemInView,
			startPadding,
			endPadding,
		} = fixedListWindow;

		const blockInView = ! usesWindowing || itemInView( nextPosition );

		const isDragging = draggedClientIds?.length > 0;
		if (
			usesWindowing &&
			! isDragging &&
			! blockInView &&
			nextPosition > start
		) {
			// found the end of the window, don't bother processing the rest of the items
			break;
		}
		const style = usesWindowing
			? {
					paddingTop: start === nextPosition ? startPadding : 0,
					paddingBottom: end === nextPosition ? endPadding : 0,
			  }
			: undefined;

		const position = index + 1;
		// If the string value changes, it's used to trigger an animation change.
		// This may be removed if we use a different animation library in the future.
		const updatedPath =
			path.length > 0
				? `${ path }_${ position }`
				: `${ position }`;
		const hasNestedBlocks =
			showNestedBlocks && !! innerBlocks && !! innerBlocks.length;
		const hasNestedAppender = itemHasAppender( clientId );
		const hasNestedBranch = hasNestedBlocks || hasNestedAppender;

		const isSelected = isClientIdSelected( clientId, selectedClientIds );
		const isSelectedBranch =
			isBranchSelected || ( isSelected && hasNestedBranch );

		// Logic needed to target the last item of a selected branch which might be deeply nested.
		// This is currently only needed for styling purposes. See: `.is-last-of-selected-branch`.
		const isLastBlock = index === blockCount - 1;
		const isLast = isSelected || ( isLastOfBranch && isLastBlock );
		const isLastOfSelectedBranch =
			isLastOfBranch && ! hasNestedBranch && isLastBlock;

		const isExpanded = hasNestedBranch
			? expandedState[ clientId ] ?? true
			: undefined;


		// Make updates to the selected or dragged blocks synchronous,
		// but asynchronous for any other block.
		const isDragged = !! draggedClientIds?.includes( clientId );

		listItems.push(
			<Fragment key={ clientId }>
				{ ( isDragged || blockInView ) && (
					<ListViewBlock
						block={ block }
						selectBlock={ selectBlock }
						isDragged={ isDragged }
						isSelected={ isSelected }
						isBranchSelected={ isSelectedBranch }
						isLastOfSelectedBranch={ isLastOfSelectedBranch }
						level={ level }
						position={ position }
						rowCount={ rowCount }
						siblingBlockCount={ blockCount }
						showBlockMovers={ showBlockMovers }
						path={ updatedPath }
						isExpanded={ isExpanded }
						listPosition={ nextPosition }
						style={ style }
					/>
				) }
				{ hasNestedBranch && isExpanded && ! isDragged && (
					<ListViewBranch
						blocks={ innerBlocks }
						selectBlock={ selectBlock }
						isBranchSelected={ isSelectedBranch }
						isLastOfBranch={ isLast }
						showAppender={ showAppender }
						showBlockMovers={ showBlockMovers }
						showNestedBlocks={ showNestedBlocks }
						parentBlockClientId={ clientId }
						level={ level + 1 }
						path={ updatedPath }
						listPosition={ nextPosition + 1 }
						fixedListWindow={ fixedListWindow }
					/>
				) }
			</Fragment>
		);
	}

	return (
		<>
			{ listItems }
			{ hasAppender && (
				<ListViewAppender
					parentBlockClientId={ parentBlockClientId }
					position={ rowCount }
					rowCount={ appenderPosition }
					level={ level }
					path={
						path.length > 0
							? `${ path }_${ appenderPosition }`
							: `${ appenderPosition }`
					}
				/>
			) }
		</>
	);
}

ListViewBranch.defaultProps = {
	selectBlock: () => {},
};
