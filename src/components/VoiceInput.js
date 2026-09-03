/**
 * Voice Input Component.
 * Implements in-browser speech recognition via Web Speech API.
 */

import { useState, useEffect, useRef } from '@wordpress/element';
import { Button, Tooltip } from '@wordpress/components';
import { audio } from '@wordpress/icons';

export default function VoiceInput( { onTranscript, disabled } ) {
	const [ isListening, setIsListening ] = useState( false );
	const [ isSupported, setIsSupported ] = useState( true );
	const recognitionRef = useRef( null );

	useEffect( () => {
		const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
		if ( ! SpeechRecognition ) {
			setIsSupported( false );
			return;
		}

		const recognition = new SpeechRecognition();
		recognition.continuous = false;
		recognition.interimResults = true;
		recognition.lang = navigator.language || 'en-US';

		recognition.onstart = () => {
			setIsListening( true );
		};

		recognition.onresult = ( event ) => {
			let currentTranscript = '';
			for ( let i = event.resultIndex; i < event.results.length; i++ ) {
				currentTranscript += event.results[ i ][ 0 ].transcript;
			}
			if ( onTranscript ) {
				onTranscript( currentTranscript );
			}
		};

		recognition.onerror = ( event ) => {
			console.warn( 'Speech recognition error:', event.error );
			setIsListening( false );
		};

		recognition.onend = () => {
			setIsListening( false );
		};

		recognitionRef.current = recognition;

		return () => {
			if ( recognitionRef.current ) {
				recognitionRef.current.abort();
			}
		};
	}, [ onTranscript ] );

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
				console.warn( 'Failed to start speech recognition:', err );
			}
		}
	};

	if ( ! isSupported ) {
		return null;
	}

	return (
		<div className="ai-voice-input-wrapper">
			<Tooltip text={ isListening ? 'Click to stop listening' : 'Speak your block requirements (Microphone)' }>
				<Button
					icon={ audio }
					className={ `ai-voice-btn ${ isListening ? 'is-listening' : '' }` }
					onClick={ toggleListening }
					disabled={ disabled }
					aria-label={ isListening ? 'Stop voice dictation' : 'Start voice dictation' }
				>
					{ isListening && <span className="ai-voice-pulse-ring" /> }
					{ isListening ? 'Listening...' : '' }
				</Button>
			</Tooltip>
		</div>
	);
}
