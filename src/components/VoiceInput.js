/**
 * Voice Input Component.
 * Implements in-browser speech recognition via Web Speech API.
 */

import { useState, useEffect, useRef } from '@wordpress/element';
import { Button, Tooltip, Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { audio } from '@wordpress/icons';

export default function VoiceInput( { onTranscript, disabled } ) {
	const [ isListening, setIsListening ] = useState( false );
	const [ isSupported, setIsSupported ] = useState( true );
	const [ permissionError, setPermissionError ] = useState( false );
	const recognitionRef = useRef( null );
	const onTranscriptRef = useRef( onTranscript );

	// Keep the latest callback in a ref instead of the effect's dependency
	// array, so a new inline function from the parent on every render
	// doesn't tear down and recreate (and thereby abort) the recognizer
	// mid-dictation.
	useEffect( () => {
		onTranscriptRef.current = onTranscript;
	}, [ onTranscript ] );

	useEffect( () => {
		const SpeechRecognition =
			window.SpeechRecognition || window.webkitSpeechRecognition;
		if ( ! SpeechRecognition ) {
			setIsSupported( false );
			return;
		}

		const recognition = new SpeechRecognition();
		recognition.continuous = false;
		recognition.interimResults = true;
		recognition.lang = window.navigator.language || 'en-US';

		let finalTranscript = '';

		recognition.onstart = () => {
			finalTranscript = '';
			setPermissionError( false );
			setIsListening( true );
		};

		recognition.onresult = ( event ) => {
			// Only commit finalized results to the prompt; interim (in-progress)
			// results are for live UI feedback only and must not be appended,
			// or repeated interim callbacks duplicate words into the prompt.
			for ( let i = event.resultIndex; i < event.results.length; i++ ) {
				if ( event.results[ i ].isFinal ) {
					finalTranscript += event.results[ i ][ 0 ].transcript;
				}
			}
			if ( finalTranscript && onTranscriptRef.current ) {
				onTranscriptRef.current( finalTranscript.trim() );
			}
		};

		recognition.onerror = ( event ) => {
			if (
				event.error === 'not-allowed' ||
				event.error === 'service-not-allowed'
			) {
				setPermissionError( true );
			}
			setIsListening( false );
		};

		recognition.onend = () => {
			setIsListening( false );
		};

		recognitionRef.current = recognition;

		return () => {
			recognitionRef.current?.abort();
		};
		// eslint-disable-next-line react-hooks/exhaustive-deps -- intentionally
		// created once; onTranscript changes flow through onTranscriptRef above.
	}, [] );

	const toggleListening = () => {
		if ( ! isSupported || disabled ) {
			return;
		}

		if ( isListening ) {
			recognitionRef.current?.stop();
			setIsListening( false );
		} else {
			try {
				recognitionRef.current?.start();
			} catch ( err ) {
				setIsListening( false );
			}
		}
	};

	if ( ! isSupported ) {
		return null;
	}

	return (
		<div className="ai-voice-input-wrapper">
			<Tooltip
				text={
					isListening
						? __( 'Click to stop listening', 'ai-block-creator' )
						: __(
								'Speak your block requirements (Microphone)',
								'ai-block-creator'
						  )
				}
			>
				<Button
					icon={ audio }
					className={ `ai-voice-btn ${
						isListening ? 'is-listening' : ''
					}` }
					onClick={ toggleListening }
					disabled={ disabled }
					aria-label={
						isListening
							? __( 'Stop voice dictation', 'ai-block-creator' )
							: __( 'Start voice dictation', 'ai-block-creator' )
					}
				>
					{ isListening && <span className="ai-voice-pulse-ring" /> }
					{ isListening
						? __( 'Listening…', 'ai-block-creator' )
						: '' }
				</Button>
			</Tooltip>
			{ permissionError && (
				<Notice
					status="warning"
					isDismissible={ true }
					onRemove={ () => setPermissionError( false ) }
					className="ai-voice-permission-notice"
				>
					{ __(
						'Microphone access was denied. Allow microphone access in your browser to use voice dictation.',
						'ai-block-creator'
					) }
				</Notice>
			) }
		</div>
	);
}
