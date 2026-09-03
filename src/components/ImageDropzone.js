/**
 * Image Dropzone & Clipboard Screenshot Pasting Component.
 */

import { useState, useEffect, useRef } from '@wordpress/element';
import { Button, Tooltip } from '@wordpress/components';
import { image as imageIcon, closeSmall } from '@wordpress/icons';

export default function ImageDropzone( { image, onImageChange, disabled } ) {
	const [ isDragging, setIsDragging ] = useState( false );
	const fileInputRef = useRef( null );

	// Listen for paste event anywhere inside modal or window
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
	}, [ disabled, onImageChange ] );

	const processFile = ( file ) => {
		if ( ! file || ! file.type.startsWith( 'image/' ) ) {
			return;
		}

		const reader = new FileReader();
		reader.onload = ( e ) => {
			if ( e.target?.result ) {
				onImageChange( e.target.result );
			}
		};
		reader.readAsDataURL( file );
	};

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
					<img src={ image } alt="Screenshot reference" className="ai-screenshot-thumb" />
					<Tooltip text="Remove screenshot">
						<Button
							icon={ closeSmall }
							className="ai-screenshot-remove"
							onClick={ () => onImageChange( null ) }
							disabled={ disabled }
							aria-label="Remove screenshot"
						/>
					</Tooltip>
				</div>
				<span className="ai-screenshot-badge">📸 Screenshot attached</span>
			</div>
		);
	}

	return (
		<div
			className={ `ai-image-dropzone ${ isDragging ? 'is-dragging' : '' }` }
			onDrop={ handleDrop }
			onDragOver={ handleDragOver }
			onDragLeave={ handleDragLeave }
		>
			<input
				type="file"
				accept="image/*"
				ref={ fileInputRef }
				style={ { display: 'none' } }
				onChange={ ( e ) => {
					if ( e.target.files && e.target.files.length > 0 ) {
						processFile( e.target.files[ 0 ] );
					}
				} }
			/>
			<Tooltip text="Drop or paste (Cmd+V) a screenshot of the block you want to create">
				<Button
					icon={ imageIcon }
					className="ai-image-btn"
					onClick={ () => fileInputRef.current?.click() }
					disabled={ disabled }
				>
					Paste / Drop Screenshot
				</Button>
			</Tooltip>
		</div>
	);
}
