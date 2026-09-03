/**
 * Image Dropzone & Clipboard Screenshot Pasting Component.
 */

import { useState, useEffect, useRef, useCallback } from '@wordpress/element';
import { Button, Tooltip, Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { image as imageIcon, closeSmall } from '@wordpress/icons';

const ALLOWED_TYPES = [ 'image/png', 'image/jpeg', 'image/webp', 'image/gif' ];
const MAX_BYTES = 4 * 1024 * 1024; // Mirrors the server-side cap in AI_Block_REST_Controller.

export default function ImageDropzone( { image, onImageChange, disabled } ) {
	const [ isDragging, setIsDragging ] = useState( false );
	const [ error, setError ] = useState( '' );
	const fileInputRef = useRef( null );

	const processFile = useCallback(
		( file ) => {
			setError( '' );

			if ( ! file || ! ALLOWED_TYPES.includes( file.type ) ) {
				setError(
					__(
						'Please use a PNG, JPEG, WEBP, or GIF image.',
						'ai-block-creator'
					)
				);
				return;
			}

			if ( file.size > MAX_BYTES ) {
				setError(
					__(
						'Screenshot is too large. Please use an image under 4MB.',
						'ai-block-creator'
					)
				);
				return;
			}

			const reader = new window.FileReader();
			reader.onload = ( e ) => {
				if ( e.target?.result ) {
					onImageChange( e.target.result );
				}
			};
			reader.readAsDataURL( file );
		},
		[ onImageChange ]
	);

	// Listen for paste event anywhere inside modal or window.
	useEffect( () => {
		const handlePaste = ( event ) => {
			if ( disabled ) {
				return;
			}
			const items = event.clipboardData?.items;
			if ( ! items ) {
				return;
			}

			for ( let i = 0; i < items.length; i++ ) {
				if ( items[ i ].type.indexOf( 'image' ) !== -1 ) {
					const blob = items[ i ].getAsFile();
					if ( blob ) {
						processFile( blob );
						event.preventDefault();
						break;
					}
				}
			}
		};

		window.addEventListener( 'paste', handlePaste );
		return () => window.removeEventListener( 'paste', handlePaste );
	}, [ disabled, processFile ] );

	const handleDrop = ( e ) => {
		e.preventDefault();
		setIsDragging( false );
		if ( disabled ) {
			return;
		}

		if ( e.dataTransfer.files && e.dataTransfer.files.length > 0 ) {
			processFile( e.dataTransfer.files[ 0 ] );
		}
	};

	const handleDragOver = ( e ) => {
		e.preventDefault();
		if ( ! disabled ) {
			setIsDragging( true );
		}
	};

	const handleDragLeave = () => {
		setIsDragging( false );
	};

	if ( image ) {
		return (
			<div className="ai-screenshot-preview">
				<div className="ai-screenshot-thumb-wrap">
					<img
						src={ image }
						alt={ __( 'Screenshot reference', 'ai-block-creator' ) }
						className="ai-screenshot-thumb"
					/>
					<Tooltip
						text={ __( 'Remove screenshot', 'ai-block-creator' ) }
					>
						<Button
							icon={ closeSmall }
							className="ai-screenshot-remove"
							onClick={ () => onImageChange( null ) }
							disabled={ disabled }
							aria-label={ __(
								'Remove screenshot',
								'ai-block-creator'
							) }
						/>
					</Tooltip>
				</div>
				<span className="ai-screenshot-badge">
					📸 { __( 'Screenshot attached', 'ai-block-creator' ) }
				</span>
			</div>
		);
	}

	return (
		<div className="ai-image-dropzone-wrapper">
			<div
				className={ `ai-image-dropzone ${
					isDragging ? 'is-dragging' : ''
				}` }
				onDrop={ handleDrop }
				onDragOver={ handleDragOver }
				onDragLeave={ handleDragLeave }
			>
				<input
					type="file"
					accept={ ALLOWED_TYPES.join( ',' ) }
					ref={ fileInputRef }
					style={ { display: 'none' } }
					onChange={ ( e ) => {
						if ( e.target.files && e.target.files.length > 0 ) {
							processFile( e.target.files[ 0 ] );
						}
					} }
				/>
				<Tooltip
					text={ __(
						'Drop or paste (Cmd+V) a screenshot of the block you want to create',
						'ai-block-creator'
					) }
				>
					<Button
						icon={ imageIcon }
						className="ai-image-btn"
						onClick={ () => fileInputRef.current?.click() }
						disabled={ disabled }
					>
						{ __( 'Paste / Drop Screenshot', 'ai-block-creator' ) }
					</Button>
				</Tooltip>
			</div>
			{ error && (
				<Notice
					status="error"
					isDismissible={ true }
					onRemove={ () => setError( '' ) }
				>
					{ error }
				</Notice>
			) }
		</div>
	);
}
